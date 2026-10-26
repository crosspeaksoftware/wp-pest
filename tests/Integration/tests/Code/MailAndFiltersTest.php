<?php

use CrossPeak\WpPest\MailMessage;
use PHPUnit\Framework\AssertionFailedError;

use function CrossPeak\WpPest\captureMail;
use function CrossPeak\WpPest\withFilter;

it('records wp_mail calls and provides recipient subject body and attachment assertions', function () {
    add_action('phpmailer_init', static function () {
        throw new LogicException('Mail capture must not invoke the transport.');
    });
    $mail = captureMail(static function () {
        expect(wp_mail('Ada <ada@example.test>, bob@example.test', 'Private notification', 'Encrypted field 東京', ['X-Test: value'], "/tmp/one.txt\r\n/tmp/two.txt"))->toBeTrue();
    });
    $mail->assertCount(1)->assertSentTo('ADA@example.test')->assertSentTo('bob@example.test')
        ->assertSubject('Private notification')->assertBodyContains('東京')->assertAttachment('/tmp/two.txt')
        ->assertSent(static fn (MailMessage $message): bool => $message->hasRecipient('ada@example.test') && $message->subject === 'Private notification');
    expect($mail->messages[0]->attributes['headers'])->toBe(['X-Test: value']);
    expect(fn () => $mail->assertNothingSent())->toThrow(AssertionFailedError::class)
        ->and(fn () => $mail->assertSentTo('missing@example.test'))->toThrow(AssertionFailedError::class)
        ->and(fn () => $mail->assertSubject('missing'))->toThrow(AssertionFailedError::class)
        ->and(fn () => $mail->assertBodyContains('plaintext'))->toThrow(AssertionFailedError::class)
        ->and(fn () => $mail->assertAttachment('/missing'))->toThrow(AssertionFailedError::class);
    captureMail(static fn () => null)->assertNothingSent();
});

it('prevents transport invocation and restores earlier short circuit filters', function () {
    $calls = 0;
    $block = static function () use (&$calls): bool {
        $calls++;

        return false;
    };
    add_filter('pre_wp_mail', $block, 5);
    $mail = captureMail(static fn () => wp_mail(['one@example.test'], 'Captured', 'Body'));
    $mail->assertCount(1);
    expect(wp_mail('one@example.test', 'Not captured', 'Body'))->toBeFalse()
        ->and($calls)->toBe(2)
        ->and(has_filter('pre_wp_mail', $block))->toBe(5);
});

it('includes nested mail captures in the outer scope and restores hooks on exceptions', function () {
    $outer = captureMail(static function () {
        captureMail(static fn () => wp_mail('inner@example.test', 'Inner', 'Body'))->assertCount(1);
        wp_mail('outer@example.test', 'Outer', 'Body');
    });
    $outer->assertCount(2)->assertSentTo('inner@example.test')->assertSentTo('outer@example.test');
    $before = has_filter('pre_wp_mail');
    expect(fn () => captureMail(static function () {
        wp_mail('discard@example.test', 'Discard', 'Body');
        throw new RuntimeException('mail callback failed');
    }))->toThrow(RuntimeException::class, 'mail callback failed');
    expect(has_filter('pre_wp_mail'))->toBe($before);
});

it('restores a hook exactly after nested scopes and failures', function () {
    $original = static fn ($value) => $value.' original';
    add_filter('wp_pest_scoped', $original, 5);
    $result = withFilter('wp_pest_scoped', static fn ($value, $suffix) => $value.$suffix, static function () {
        expect(apply_filters('wp_pest_scoped', 'start', ' scoped'))->toBe('start original scoped');
        withFilter('wp_pest_scoped', static fn () => 'inner', static function () {
            expect(apply_filters('wp_pest_scoped', 'start', ' scoped'))->toBe('inner');
        }, 20);
        expect(apply_filters('wp_pest_scoped', 'start', ' scoped'))->toBe('start original scoped');

        return 42;
    }, 10, 2);
    expect($result)->toBe(42)->and(apply_filters('wp_pest_scoped', 'start'))->toBe('start original');
    expect(fn () => withFilter('wp_pest_scoped', $original, static function () {
        remove_all_filters('wp_pest_scoped');
        throw new RuntimeException('filter failed');
    }, 5, 0))->toThrow(RuntimeException::class, 'filter failed');
    expect(apply_filters('wp_pest_scoped', 'start'))->toBe('start original');
    withFilter('wp_pest_new_hook', '__return_false', static fn () => null);
    expect(has_filter('wp_pest_new_hook'))->toBeFalse();
});
