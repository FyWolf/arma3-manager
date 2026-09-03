<?php

namespace FyWolf\Arma3Manager\Integrations\Workshop\Data;

/**
 * One published Workshop item, as this plugin needs it.
 *
 * A deliberately small projection of a large API response. Steam returns a few
 * dozen fields per item and every one carried here is one that has to keep
 * working: the id, so it can be downloaded; the title, so a human can recognise
 * it; the size, so a customer can be warned before a 10 GB mod fills their
 * disk; and `children`, which is the dependency graph.
 */
readonly class WorkshopItem
{
    /**
     * @param array<int, string> $children  Required-item ids, in the order Steam lists them.
     */
    public function __construct(
        public string $id,
        public string $title,
        public ?string $description = null,
        public ?string $previewUrl = null,
        public int $sizeBytes = 0,
        public ?int $updatedAt = null,
        public array $children = [],
        public ?int $appId = null,
        public bool $banned = false,
        public ?string $banReason = null,
    ) {}

    /**
     * Build from a `GetPublishedFileDetails` element.
     *
     * `result` of 1 means the item exists; anything else — 9 is the usual —
     * means it is deleted, private, or was never published. Those come back as
     * null rather than as an item with an empty title, because an item with an
     * empty title renders as a blank row a customer will try to install.
     *
     * @param array<string, mixed> $payload
     */
    public static function fromApi(array $payload): ?self
    {
        $id = (string) ($payload['publishedfileid'] ?? '');

        if ($id === '' || (int) ($payload['result'] ?? 0) !== 1) {
            return null;
        }

        $children = [];

        foreach ((array) ($payload['children'] ?? []) as $child) {
            $childId = (string) ($child['publishedfileid'] ?? '');

            if ($childId !== '') {
                $children[] = $childId;
            }
        }

        return new self(
            id: $id,
            title: trim((string) ($payload['title'] ?? '')) ?: $id,
            description: isset($payload['description']) ? (string) $payload['description'] : null,
            previewUrl: isset($payload['preview_url']) ? (string) $payload['preview_url'] : null,
            // Cast through string first: Steam sends file_size as a string on
            // the remote-storage endpoint and as an int on the newer one, and
            // a direct (int) on a string like "10737418240" is fine while a
            // float-decoded JSON number is not.
            sizeBytes: (int) (string) ($payload['file_size'] ?? 0),
            updatedAt: isset($payload['time_updated']) ? (int) $payload['time_updated'] : null,
            children: $children,
            appId: isset($payload['consumer_app_id']) ? (int) $payload['consumer_app_id'] : null,
            banned: (bool) ($payload['banned'] ?? false),
            banReason: isset($payload['ban_reason']) && $payload['ban_reason'] !== '' ? (string) $payload['ban_reason'] : null,
        );
    }

    public function sizeForHumans(): string
    {
        if ($this->sizeBytes <= 0) {
            return '—';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = min((int) floor(log($this->sizeBytes, 1024)), count($units) - 1);

        return round($this->sizeBytes / (1024 ** $power), $power > 1 ? 1 : 0) . ' ' . $units[$power];
    }

    /**
     * Whether this item can be installed at all.
     *
     * A banned item stays in the API with its metadata intact but cannot be
     * downloaded, so offering an install button for one produces a SteamCMD
     * failure the customer cannot act on.
     */
    public function isInstallable(): bool
    {
        return ! $this->banned;
    }
}
