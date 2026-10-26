<?php

declare(strict_types=1);

namespace CrossPeak\WpPest;

/** The arguments presented to pre_wp_mail, before transport processing. */
final readonly class MailMessage
{
    /** @var list<string> */
    public array $to;

    public string $subject;

    public string $body;

    /** @var list<string> */
    public array $attachments;

    /** @param array<string, mixed> $attributes */
    public function __construct(public array $attributes)
    {
        $this->to = self::asList($attributes['to'] ?? [], ',');
        $this->subject = (string) ($attributes['subject'] ?? '');
        $this->body = (string) ($attributes['message'] ?? '');
        $this->attachments = self::asList($attributes['attachments'] ?? [], "\n", false);
    }

    public function hasRecipient(string $email): bool
    {
        foreach ($this->to as $recipient) {
            if (preg_match('/<([^>]+)>/', $recipient, $matches) === 1) {
                $recipient = $matches[1];
            }
            if (strcasecmp(trim($recipient), trim($email)) === 0) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private static function asList(mixed $value, string $separator, bool $trim = true): array
    {
        $values = is_array($value) ? $value : explode($separator, str_replace("\r\n", "\n", (string) $value));

        return array_values(array_filter(array_map(static fn ($item): string => $trim ? trim((string) $item) : (string) $item, $values), static fn (string $item): bool => $item !== ''));
    }
}
