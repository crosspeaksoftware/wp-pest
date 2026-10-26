<?php

declare(strict_types=1);

namespace CrossPeak\WpPest;

use PHPUnit\Framework\Assert;

final readonly class MailRecorder
{
    /** @param list<MailMessage> $messages */
    public function __construct(public array $messages) {}

    public function assertCount(int $expected): self
    {
        Assert::assertCount($expected, $this->messages, 'Unexpected number of captured wp_mail calls.');

        return $this;
    }

    public function assertNothingSent(): self
    {
        return $this->assertCount(0);
    }

    /** Assert that one message satisfies all conditions in the predicate. */
    public function assertSent(callable $predicate, string $description = 'No captured mail matched the predicate.'): self
    {
        foreach ($this->messages as $message) {
            if ($predicate($message)) {
                return $this;
            }
        }
        Assert::fail($description);
    }

    public function assertSentTo(string $email): self
    {
        return $this->assertSent(static fn (MailMessage $message): bool => $message->hasRecipient($email), 'No captured mail was addressed to '.$email.'.');
    }

    public function assertSubject(string $subject): self
    {
        return $this->assertSent(static fn (MailMessage $message): bool => $message->subject === $subject, 'No captured mail had subject '.$subject.'.');
    }

    public function assertBodyContains(string $text): self
    {
        return $this->assertSent(static fn (MailMessage $message): bool => str_contains($message->body, $text), 'No captured mail body contained '.$text.'.');
    }

    public function assertAttachment(string $path): self
    {
        return $this->assertSent(static fn (MailMessage $message): bool => in_array($path, $message->attachments, true), 'No captured mail included attachment '.$path.'.');
    }
}
