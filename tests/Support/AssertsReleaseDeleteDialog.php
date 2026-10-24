<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * The admin's Delete confirmation on the Adult screens (issue #1167; docs/proposals/adult-redesign/SPEC.md 5.11): one
 * releaseDeleteDialog in the redesign's dialog frame, titled "Delete this release?" with no subtitle, the release name
 * in its box, the note, then Cancel and the red Delete.
 */
trait AssertsReleaseDeleteDialog
{
    /** @return array<string, array{string}> the roles that never see Delete */
    public static function nonAdminRoles(): array
    {
        return ['user' => ['User'], 'moderator' => ['Moderator']];
    }

    protected function assertReleaseDeleteDialog(string $html): void
    {
        $this->assertSame(1, substr_count($html, 'x-data="releaseDeleteDialog"'), 'one delete dialog');
        $dialog = (string) preg_replace('/>\s+</', '><', substr($html, (int) strpos($html, 'x-data="releaseDeleteDialog"'), 3000));
        foreach ([
            '<div data-modal-dialog x-show="open" x-cloak class="tv-scrim" x-on:click.self="close()">',
            '<div class="tv-dialog is-release-delete" aria-describedby="release-delete-name release-delete-note" role="dialog" aria-modal="true" aria-labelledby="release-delete-dialog-title"',
            '>Delete this release?</h2></div><button type="button" class="tv-action tv-dialog-close"',
            '<div class="tv-dialog-body"><p class="tv-delete-name" id="release-delete-name" x-text="name"></p>'
                .'<p class="tv-delete-note" id="release-delete-note">This permanently removes the NZB, its pictures and clip, and everything linked to it. It can’t be undone.</p>'
                .'<div class="tv-dialog-actions"><button type="button" class="tv-details-button is-secondary" x-ref="cancel" x-on:click="close">Cancel</button>'
                .'<button type="button" class="tv-details-button is-danger" x-on:click="deleteRelease" x-bind:disabled="busy"><i class="fas fa-trash" aria-hidden="true"></i>Delete</button></div></div>',
        ] as $part) {
            $this->assertStringContainsString($part, $dialog);
        }
    }

    protected function assertNoReleaseDelete(string $html, string $where): void
    {
        foreach (['data-delete-release', 'releaseDeleteDialog', 'Delete this release?', 'fa-trash'] as $absent) {
            $this->assertStringNotContainsString($absent, $html, $where);
        }
    }
}
