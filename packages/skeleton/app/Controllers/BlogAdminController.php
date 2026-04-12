<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Post;
use Nyholm\Psr7\Response;
use Preflow\Auth\Http\AuthMiddleware;
use Preflow\Core\Http\Session\SessionInterface;
use Preflow\Data\DataManager;
use Preflow\Data\SortDirection;
use Preflow\Routing\Attributes\Get;
use Preflow\Routing\Attributes\Middleware;
use Preflow\Routing\Attributes\Post as HttpPost;
use Preflow\Routing\Attributes\Route;
use Preflow\View\AssetCollector;
use Preflow\View\TemplateEngineInterface;
use Psr\Http\Message\ServerRequestInterface;

#[Route('/admin')]
#[Middleware(AuthMiddleware::class)]
final class BlogAdminController
{
    public function __construct(
        private readonly DataManager $dm,
        private readonly TemplateEngineInterface $engine,
        private readonly AssetCollector $assets,
    ) {}

    #[Get('/')]
    public function index(ServerRequestInterface $request): Response
    {
        $posts = $this->dm->query(Post::class)
            ->orderBy('uuid', SortDirection::Desc)
            ->get();

        return $this->renderPage('admin/_index.twig', [
            'posts' => $posts->items(),
        ]);
    }

    #[Get('/create')]
    public function create(ServerRequestInterface $request): Response
    {
        return $this->renderPage('admin/_form.twig', [
            'post' => null,
            'pageTitle' => 'New Post',
        ]);
    }

    #[Get('/edit/{uuid}')]
    public function edit(ServerRequestInterface $request): Response
    {
        $uuid = $request->getAttribute('uuid');
        $post = $this->dm->find(Post::class, $uuid);

        if ($post === null) {
            return new Response(302, ['Location' => '/admin']);
        }

        return $this->renderPage('admin/_form.twig', [
            'post' => $post,
            'pageTitle' => 'Edit Post',
        ]);
    }

    #[HttpPost('/save')]
    public function save(ServerRequestInterface $request): Response
    {
        $body = (array) $request->getParsedBody();
        $session = $request->getAttribute(SessionInterface::class);

        $title = trim((string) ($body['title'] ?? ''));
        $slug = trim((string) ($body['slug'] ?? ''));
        $postBody = trim((string) ($body['body'] ?? ''));
        $status = (string) ($body['status'] ?? 'draft');
        $uuid = (string) ($body['uuid'] ?? '');

        if ($title === '' || $postBody === '') {
            $session?->flash('error', 'Title and body are required.');
            return new Response(302, ['Location' => $uuid !== '' ? '/admin/edit/' . $uuid : '/admin/create']);
        }

        if ($slug === '') {
            $slug = $this->slugify($title);
        }

        if ($uuid !== '') {
            $post = $this->dm->find(Post::class, $uuid);
            if ($post === null) {
                return new Response(302, ['Location' => '/admin']);
            }
        } else {
            $post = new Post();
            $post->uuid = $this->generateUuid();
            $post->created_at = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        }

        $post->title = $title;
        $post->slug = $slug;
        $post->body = $postBody;
        $post->status = $status;
        $post->updated_at = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->dm->save($post);

        $session?->flash('success', $uuid !== '' ? 'Post updated.' : 'Post created.');
        return new Response(302, ['Location' => '/admin']);
    }

    #[HttpPost('/delete/{uuid}')]
    public function delete(ServerRequestInterface $request): Response
    {
        $uuid = $request->getAttribute('uuid');
        $session = $request->getAttribute(SessionInterface::class);

        $this->dm->delete(Post::class, $uuid);

        $session?->flash('success', 'Post deleted.');
        return new Response(302, ['Location' => '/admin']);
    }

    private function renderPage(string $template, array $context = []): Response
    {
        $html = $this->engine->render($template, $context);
        $head = $this->assets->renderHead();
        $body = $this->assets->renderAssets();
        if ($head !== '') {
            $html = str_replace('</head>', $head . '</head>', $html);
        }
        if ($body !== '') {
            $html = str_replace('</body>', $body . '</body>', $html);
        }
        return new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], $html);
    }

    private function slugify(string $text): string
    {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $text));
        return trim($slug, '-');
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
