<?php

declare(strict_types=1);

namespace App\Support\Settings\Sections;

use App\Support\Settings\PipelineStage;
use App\Support\Settings\SettingCard;
use App\Support\Settings\SettingDefinition;
use App\Support\Settings\SettingSection;
use App\Support\Settings\SettingsSectionProvider;
use App\Support\Settings\SettingType;

/**
 * What visitors see: the wrapper around the index, and what the index is allowed to show them.
 *
 * Nothing on this page changes what gets indexed -- it is the last stage of the pipeline,
 * where finished releases are published.
 */
final class WebsiteSection implements SettingsSectionProvider
{
    public static function section(): SettingSection
    {
        return new SettingSection(
            id: 'website',
            title: 'Website',
            description: 'Branding, what browse and the feeds are allowed to show, and how logins behave.',
            icon: 'fas fa-globe',
            stage: PipelineStage::Publish,
            cards: [
                new SettingCard(
                    id: 'branding',
                    title: 'Branding & layout',
                    description: 'The text wrapped around every public page.',
                    icon: 'fas fa-pen-nib',
                    settings: [
                        new SettingDefinition(
                            key: 'strapline',
                            label: 'Strapline',
                            help: 'Shown in the header of every public page, beside the site name.',
                            type: SettingType::Text,
                            icon: 'fas fa-quote-right',
                        ),
                        new SettingDefinition(
                            key: 'metatitle',
                            label: 'Meta title stem',
                            help: 'Appended to the title tag of every page, after the page\'s own title.',
                            type: SettingType::Text,
                            icon: 'fas fa-heading',
                        ),
                        new SettingDefinition(
                            key: 'metadescription',
                            label: 'Meta description stem',
                            help: 'Appended to the meta description of every page.',
                            type: SettingType::Text,
                            icon: 'fas fa-align-left',
                        ),
                        new SettingDefinition(
                            key: 'metakeywords',
                            label: 'Meta keywords stem',
                            help: 'Appended to the meta keywords of every page. Comma-separated.',
                            type: SettingType::Text,
                            icon: 'fas fa-tags',
                        ),
                        new SettingDefinition(
                            key: 'footer',
                            label: 'Footer text',
                            help: 'Shown in the footer of every public page.',
                            type: SettingType::Text,
                            icon: 'fas fa-shoe-prints',
                        ),
                        new SettingDefinition(
                            key: 'home_link',
                            label: 'Home link',
                            help: 'Where the site logo and the home link go. A path relative to the site root, such as <code>/</code> or <code>/browse</code>.',
                            type: SettingType::Text,
                            icon: 'fas fa-house',
                            placeholder: '/',
                        ),
                        new SettingDefinition(
                            key: 'dereferrer_link',
                            label: 'Dereferrer prefix',
                            help: 'Prepended to outbound links so the destination does not see which page the visitor came from. Leave blank to link out directly.',
                            type: SettingType::Text,
                            icon: 'fas fa-arrow-up-right-from-square',
                        ),
                        new SettingDefinition(
                            key: 'tandc',
                            label: 'Terms and conditions',
                            help: 'The body of the terms page. HTML is allowed.',
                            type: SettingType::Textarea,
                            icon: 'fas fa-scale-balanced',
                        ),
                    ],
                ),
                new SettingCard(
                    id: 'browse',
                    title: 'Browse & display',
                    description: 'What browse, search, the API and the feeds put in front of a user.',
                    icon: 'fas fa-list',
                    settings: [
                        new SettingDefinition(
                            key: 'showpasswordedrelease',
                            label: 'Passworded releases',
                            help: 'Whether releases the password check flagged are visible in browse, search, the API and the feeds. Hiding them does not delete them.',
                            type: SettingType::Enum,
                            options: [0 => 'Hide passworded releases', 1 => 'Show everything'],
                            icon: 'fas fa-lock',
                        ),
                        SettingDefinition::bool(
                            'grabstatus',
                            'Count downloads',
                            'Record a grab against the release and the user each time an NZB is downloaded. Turning this off leaves the download counts on browse frozen where they are.',
                            'fas fa-download',
                        ),
                        SettingDefinition::bool(
                            'trailers_display',
                            'Show trailers',
                            'Fetch and embed trailers on movie and TV detail pages. Trakt trailers need a Trakt API key in the environment; TrailerAddict needs nothing.',
                            'fas fa-film',
                        ),
                        new SettingDefinition(
                            key: 'trailers_size_x',
                            label: 'Trailer width',
                            help: 'Width of the embedded trailer player. Default 480.',
                            type: SettingType::Int,
                            unit: 'pixels',
                            rules: ['required', 'integer', 'min:1', 'max:4096'],
                            icon: 'fas fa-arrows-left-right',
                        ),
                        new SettingDefinition(
                            key: 'trailers_size_y',
                            label: 'Trailer height',
                            help: 'Height of the embedded trailer player. Default 345.',
                            type: SettingType::Int,
                            unit: 'pixels',
                            rules: ['required', 'integer', 'min:1', 'max:4096'],
                            icon: 'fas fa-arrows-up-down',
                        ),
                    ],
                ),
                new SettingCard(
                    id: 'sessions',
                    title: 'Sessions & access',
                    description: 'Who may be signed in, and from how many places at once. Who is allowed to sign up in the first place lives on <a class="text-primary-700 underline dark:text-primary-300" href="'.route('admin.registrations.index').'">Registrations</a>.',
                    icon: 'fas fa-user-shield',
                    settings: [
                        new SettingDefinition(
                            key: 'single_active_session',
                            label: 'Single active session',
                            help: 'The anti-account-sharing policy. It applies from the next login onward: turning it on does not end sessions that already exist.',
                            type: SettingType::Enum,
                            options: [
                                0 => 'Off — allow concurrent devices',
                                1 => "On — a new login ends the account's other logins",
                            ],
                            icon: 'fas fa-laptop',
                        ),
                    ],
                ),
            ],
        );
    }
}
