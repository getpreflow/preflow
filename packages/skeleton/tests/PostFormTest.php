<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use App\Components\PostForm\PostForm;

final class PostFormTest extends TestCase
{
    public function test_create_mode_has_empty_fields(): void
    {
        $form = new PostForm();
        $form->setProps(['action' => '/admin/save']);
        $form->resolveState();

        $this->assertSame('', $form->uuid);
        $this->assertSame('', $form->title);
        $this->assertSame('', $form->slug);
        $this->assertSame('', $form->body);
        $this->assertSame('draft', $form->status);
        $this->assertSame('/admin/save', $form->action);
        $this->assertFalse($form->isEdit);
    }

    public function test_edit_mode_populates_fields(): void
    {
        $form = new PostForm();
        $form->setProps([
            'action' => '/admin/save',
            'uuid' => 'post-1',
            'title' => 'Hello World',
            'slug' => 'hello-world',
            'body' => 'Post body here.',
            'status' => 'published',
        ]);
        $form->resolveState();

        $this->assertSame('post-1', $form->uuid);
        $this->assertSame('Hello World', $form->title);
        $this->assertSame('hello-world', $form->slug);
        $this->assertSame('Post body here.', $form->body);
        $this->assertSame('published', $form->status);
        $this->assertTrue($form->isEdit);
    }
}
