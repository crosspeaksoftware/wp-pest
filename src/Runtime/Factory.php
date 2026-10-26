<?php

declare(strict_types=1);

namespace CrossPeak\WpPest\Runtime;

final class Factory
{
    public readonly UserFactory $user;

    public readonly PostFactory $post;

    public readonly PostFactory $page;

    public readonly TermFactory $term;

    public readonly AttachmentFactory $attachment;

    public function __construct()
    {
        $this->user = new UserFactory;
        $this->post = new PostFactory;
        $this->page = new PostFactory('page');
        $this->term = new TermFactory;
        $this->attachment = new AttachmentFactory;
    }
}
