<?php

declare(strict_types=1);

namespace JustinHoltWeb\Appleseed\Tests\Support;

use CraftCms\Cms\Cms;
use CraftCms\Cms\Edition;
use CraftCms\Cms\Entry\Data\EntryType;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Field\Link as LinkField;
use CraftCms\Cms\Field\Matrix;
use CraftCms\Cms\Field\PlainText;
use CraftCms\Cms\FieldLayout\FieldLayout;
use CraftCms\Cms\FieldLayout\LayoutElements\CustomField;
use CraftCms\Cms\FieldLayout\LayoutElements\Entries\EntryTitleField;
use CraftCms\Cms\Mcp\McpActor;
use CraftCms\Cms\Section\Data\Section;
use CraftCms\Cms\Section\Data\SectionSiteSettings;
use CraftCms\Cms\Section\Enums\SectionType;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\EntryTypes;
use CraftCms\Cms\Support\Facades\Fields;
use CraftCms\Cms\Support\Facades\Sections;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\Support\Facades\UserGroups;
use CraftCms\Cms\Support\Facades\UserPermissions;
use CraftCms\Cms\User\Elements\User;
use Illuminate\Http\Request;
use JustinHoltWeb\Appleseed\Mcp\AppleseedTools;
use JustinHoltWeb\Appleseed\Models\Settings;

/**
 * Real content: a `news` channel whose entries have a plain text `body` (HTML), a Link field `cta`
 * and a Matrix field `blocks` whose `linkBlock` entries have their own Link field `target` and
 * plain text `text`, all saved through Craft's own services.
 */
trait CreatesContent
{
    protected ?Section $section = null;

    protected ?EntryType $entryType = null;

    protected ?EntryType $blockType = null;

    protected function settings(): Settings
    {
        return app(Settings::class);
    }

    protected function section(): Section
    {
        if ($this->section !== null) {
            return $this->section;
        }

        $body = new PlainText(['name' => 'Body', 'handle' => 'body', 'multiline' => true]);
        expect(Fields::saveField($body))->toBeTrue();

        $cta = new LinkField(['name' => 'Call to Action', 'handle' => 'cta', 'types' => ['url']]);
        expect(Fields::saveField($cta))->toBeTrue();

        $target = new LinkField(['name' => 'Target', 'handle' => 'target', 'types' => ['url']]);
        expect(Fields::saveField($target))->toBeTrue();

        $text = new PlainText(['name' => 'Text', 'handle' => 'text', 'multiline' => true]);
        expect(Fields::saveField($text))->toBeTrue();

        $this->blockType = new EntryType(['name' => 'Link Block', 'handle' => 'linkBlock']);
        $blockLayout = new FieldLayout(['type' => Entry::class]);
        $blockLayout->setTabs([['name' => 'Content', 'elements' => [new CustomField($target), new CustomField($text)]]]);
        $this->blockType->setFieldLayout($blockLayout);
        expect(EntryTypes::saveEntryType($this->blockType))->toBeTrue();

        $blocks = new Matrix(['name' => 'Blocks', 'handle' => 'blocks']);
        $blocks->setEntryTypes([$this->blockType]);
        expect(Fields::saveField($blocks))->toBeTrue();

        $layout = new FieldLayout(['type' => Entry::class]);
        $layout->setTabs([[
            'name' => 'Content',
            'elements' => [new EntryTitleField, new CustomField($body), new CustomField($cta), new CustomField($blocks)],
        ]]);

        $this->entryType = new EntryType(['name' => 'Article', 'handle' => 'article']);
        $this->entryType->setFieldLayout($layout);
        expect(EntryTypes::saveEntryType($this->entryType))->toBeTrue();

        $section = new Section([
            'name' => 'News',
            'handle' => 'news',
            'type' => SectionType::Channel,
            'siteSettings' => [
                new SectionSiteSettings([
                    'siteId' => Sites::getPrimarySite()->id,
                    'enabledByDefault' => true,
                    'hasUrls' => true,
                    'uriFormat' => 'news/{slug}',
                    'template' => 'news/_entry',
                ]),
            ],
        ]);
        $section->setEntryTypes([$this->entryType]);
        expect(Sections::saveSection($section))->toBeTrue();

        return $this->section = $section;
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    protected function createEntry(string $title = 'Article', array $fields = []): Entry
    {
        $section = $this->section();

        $entry = new Entry([
            'sectionId' => $section->id,
            'typeId' => $this->entryType?->id,
            'siteId' => Sites::getPrimarySite()->id,
            'title' => $title,
            'enabled' => true,
        ]);
        $entry->setAuthorIds([$this->admin()->id]);

        foreach ($fields as $handle => $value) {
            $entry->setFieldValue($handle, $value);
        }

        expect(Elements::saveElement($entry))->toBeTrue(json_encode($entry->errors()->getMessages()) ?: '');

        return $entry;
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    protected function updateEntry(Entry $entry, array $fields): Entry
    {
        $entry = Entry::find()->id($entry->id)->status(null)->one();
        expect($entry)->toBeInstanceOf(Entry::class);

        foreach ($fields as $handle => $value) {
            $entry->setFieldValue($handle, $value);
        }

        expect(Elements::saveElement($entry))->toBeTrue(json_encode($entry->errors()->getMessages()) ?: '');

        return $entry;
    }

    /**
     * Matrix blocks in Craft's serialized form.
     *
     * @param  list<array{target?: string, text?: string}>  $blocks
     * @return array<string, mixed>
     */
    protected function blocks(array $blocks): array
    {
        $this->section();
        $entries = [];

        foreach (array_values($blocks) as $i => $fields) {
            $entries['new'.($i + 1)] = ['type' => 'linkBlock', 'enabled' => true, 'fields' => $fields];
        }

        return ['sortOrder' => array_keys($entries), 'entries' => $entries];
    }

    /** The primary site's base URL, without a trailing slash. */
    protected function siteUrl(): string
    {
        return rtrim((string) Sites::getPrimarySite()->getBaseUrl(), '/');
    }

    protected function admin(): User
    {
        $user = User::find()->admin()->one();
        expect($user)->toBeInstanceOf(User::class);

        return $user;
    }

    /**
     * An active, non-admin user with exactly these permissions (plus `accessCp`).
     *
     * @param  list<string>  $permissions
     */
    protected function userWith(array $permissions, string $username = 'editor'): User
    {
        $user = new User(['username' => $username, 'email' => $username.'@example.com', 'active' => true]);
        expect(Elements::saveElement($user))->toBeTrue();

        $permissions = ['accessCp', ...$permissions];

        // On Team every non-admin shares the Team group's permissions; per-user ones are Pro.
        if (Edition::get() === Edition::Team) {
            UserPermissions::saveGroupPermissions(UserGroups::getTeamGroup()->id, $permissions);
        } else {
            UserPermissions::saveUserPermissions($user->id, $permissions);
        }

        return User::find()->id($user->id)->status(null)->one();
    }

    /**
     * Appleseed's MCP tools, acting as this user. MCP reads the user from Craft's auth guard;
     * outside HTTP the request has no resolver.
     */
    protected function mcpAs(User $user): AppleseedTools
    {
        $this->actingAs($user, Cms::config()->authGuard);
        $request = Request::create('/');
        $request->setUserResolver(fn () => $user);
        app()->instance(McpActor::class, new McpActor($request));
        app()->forgetInstance(AppleseedTools::class);

        return app(AppleseedTools::class);
    }
}
