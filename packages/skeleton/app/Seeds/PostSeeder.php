<?php

declare(strict_types=1);

namespace App\Seeds;

use Preflow\Data\DataManager;
use App\Models\Post;

final class PostSeeder
{
    public function run(DataManager $data): void
    {
        $posts = [
            ['title' => 'Getting Started with Preflow', 'slug' => 'getting-started', 'body' => 'Preflow is a modern PHP framework that puts HTML first. Components render on the server, CSS and JS are inlined, and interactivity comes from HTMX — not JavaScript frameworks.', 'status' => 'published'],
            ['title' => 'Components in Preflow', 'slug' => 'components', 'body' => 'A Preflow component is a PHP class paired with a Twig template. State is loaded in resolveState(), actions handle user interactions, and CSS/JS live right in the template file.', 'status' => 'published'],
            ['title' => 'The Asset Pipeline', 'slug' => 'asset-pipeline', 'body' => 'Preflow collects all CSS and JS from rendered components, deduplicates by content hash, and injects them inline into the HTML. Zero external requests. Every style tag gets a CSP nonce.', 'status' => 'published'],
            ['title' => 'HTMX Integration', 'slug' => 'htmx-integration', 'body' => 'The hypermedia driver abstracts HTMX behind an interface. Component actions are dispatched through signed tokens, verified with HMAC-SHA256, and protected by a 5-layer security pipeline.', 'status' => 'published'],
            ['title' => 'Multi-Storage Data Layer', 'slug' => 'data-layer', 'body' => 'Different models can use different storage backends. SQLite for your database, JSON files for config. The DataManager routes queries to the right driver based on model attributes.', 'status' => 'published'],
            ['title' => 'Draft: Upcoming Features', 'slug' => 'upcoming', 'body' => 'This is a draft post that should not appear in the published filter.', 'status' => 'draft'],
        ];

        foreach ($posts as $i => $postData) {
            $post = new Post();
            $post->uuid = 'post-' . ($i + 1);
            $post->title = $postData['title'];
            $post->slug = $postData['slug'];
            $post->body = $postData['body'];
            $post->status = $postData['status'];
            $data->save($post);
        }
    }
}
