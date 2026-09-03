<?php

namespace FyWolf\Arma3Manager\Integrations\Workshop;

use FyWolf\Arma3Manager\Integrations\Workshop\Data\WorkshopItem;
use FyWolf\Arma3Manager\Support\WorkshopId;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Steam Workshop metadata.
 *
 * Two endpoints with very different requirements, and the split is the whole
 * reason this plugin works with no credentials:
 *
 *  - **`GetPublishedFileDetails`** (ISteamRemoteStorage) is an unauthenticated
 *    POST that takes a list of ids and returns their metadata, including
 *    `children` — which is how a Workshop item declares its required items, and
 *    therefore the dependency graph, for free.
 *  - **`QueryFiles`** (IPublishedFileService) is what powers *search*, and it
 *    needs a Steam Web API key.
 *
 * So without a key everything works except the search box, and the page says so
 * rather than appearing broken. `canSearch()` is that one predicate.
 *
 * ## It never downloads anything
 *
 * Arma 3 Workshop items cannot be fetched by an anonymous SteamCMD login — the
 * account has to own Arma 3. Rather than hold a Steam credential in the panel,
 * downloads are performed by the *server's own* container using the Steam
 * account already on its egg. This class therefore stops at metadata, and
 * `ModService` writes the manifest the container reads.
 */
class SteamWorkshopClient
{
    public function canSearch(): bool
    {
        return filled(config('arma3-manager.workshop.api_key'));
    }

    /**
     * Fetch one item, or null if Steam does not have it.
     */
    public function item(string $id): ?WorkshopItem
    {
        if (! WorkshopId::isValid($id)) {
            return null;
        }

        return $this->items([$id])[$id] ?? null;
    }

    /**
     * Fetch many items in one request, keyed by id.
     *
     * Batched rather than looped on purpose: resolving a forty-mod preset one
     * request at a time is forty round trips, and Steam rate-limits by IP —
     * so the loop version starts failing exactly when somebody imports the
     * large preset this feature exists for.
     *
     * @param array<int, string> $ids
     *
     * @return array<string, WorkshopItem>
     */
    public function items(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, WorkshopId::isValid(...))));

        if ($ids === []) {
            return [];
        }

        $found = [];
        $missing = [];

        foreach ($ids as $id) {
            $cached = Cache::get($this->cacheKey($id));

            if ($cached instanceof WorkshopItem) {
                $found[$id] = $cached;
            } else {
                $missing[] = $id;
            }
        }

        // Steam accepts a generous number per call but not an unbounded one,
        // and a request that is too large fails as a whole rather than
        // returning what it could.
        foreach (array_chunk($missing, 100) as $chunk) {
            foreach ($this->fetch($chunk) as $id => $item) {
                Cache::put($this->cacheKey($id), $item, (int) config('arma3-manager.cache.item', 3600));
                $found[$id] = $item;
            }
        }

        // Returned in the order asked for. The caller is usually rendering a
        // load order, where order is meaning rather than presentation.
        $ordered = [];

        foreach ($ids as $id) {
            if (isset($found[$id])) {
                $ordered[$id] = $found[$id];
            }
        }

        return $ordered;
    }

    /**
     * @param array<int, string> $ids
     *
     * @return array<string, WorkshopItem>
     */
    private function fetch(array $ids): array
    {
        // The endpoint is form-encoded and indexes its ids — publishedfileids[0],
        // publishedfileids[1]. It is NOT JSON, and a JSON body returns a 200
        // with an empty result set rather than an error.
        $payload = ['itemcount' => count($ids)];

        foreach (array_values($ids) as $index => $id) {
            $payload["publishedfileids[$index]"] = $id;
        }

        try {
            $response = Http::asForm()
                ->connectTimeout((int) config('arma3-manager.http.connect_timeout', 4))
                ->timeout((int) config('arma3-manager.http.timeout', 8))
                ->retry((int) config('arma3-manager.http.retries', 2), 200, throw: false)
                ->post((string) config('arma3-manager.workshop.remote_storage_url'), $payload);
        } catch (Throwable) {
            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        $items = [];

        foreach ((array) $response->json('response.publishedfiledetails', []) as $element) {
            $item = WorkshopItem::fromApi((array) $element);

            if ($item !== null) {
                $items[$item->id] = $item;
            }
        }

        return $items;
    }

    /**
     * Search the Workshop. Empty without an API key.
     *
     * @return array<int, WorkshopItem>
     */
    public function search(string $term, int $page = 1, int $perPage = 24): array
    {
        if (! $this->canSearch() || trim($term) === '') {
            return [];
        }

        $key = 'a3m:search:' . md5(strtolower(trim($term)) . ":$page:$perPage");

        return Cache::remember($key, (int) config('arma3-manager.cache.search', 900), function () use ($term, $page, $perPage) {
            try {
                $response = Http::acceptJson()
                    ->connectTimeout((int) config('arma3-manager.http.connect_timeout', 4))
                    ->timeout((int) config('arma3-manager.http.timeout', 8))
                    ->retry((int) config('arma3-manager.http.retries', 2), 200, throw: false)
                    ->get((string) config('arma3-manager.workshop.query_url'), [
                        'key' => config('arma3-manager.workshop.api_key'),
                        'appid' => (int) config('arma3-manager.workshop.app_id', 107410),
                        'search_text' => $term,
                        'page' => max(1, $page),
                        'numperpage' => max(1, min(100, $perPage)),
                        // 0 = RankedByVote. Ordering by relevance alone buries
                        // CBA_A3 under a hundred reuploads of it.
                        'query_type' => 0,
                        'return_details' => true,
                        'return_children' => true,
                        'return_previews' => true,
                    ]);
            } catch (Throwable) {
                return [];
            }

            if (! $response->successful()) {
                return [];
            }

            $items = [];

            foreach ((array) $response->json('response.publishedfiledetails', []) as $element) {
                $element = (array) $element;

                // QueryFiles omits `result` on a successful row, where
                // GetPublishedFileDetails always sends it. Defaulting to 1 here
                // rather than in the DTO keeps the DTO strict for the endpoint
                // that does send it.
                $element['result'] ??= 1;

                $item = WorkshopItem::fromApi($element);

                if ($item !== null && $item->isInstallable()) {
                    $items[] = $item;
                }
            }

            return $items;
        });
    }

    /**
     * Every id required to run the given ids, including the ids themselves.
     *
     * Walks `children` breadth-first and returns dependencies **before** the
     * things that need them, because that is the order Arma has to load them
     * in: CBA_A3 before ACE, or ACE fails at startup.
     *
     * Depth is bounded and visited ids are tracked, so a mod that declares a
     * dependency on something that depends on it back terminates instead of
     * walking forever.
     *
     * @param array<int, string> $ids
     *
     * @return array<int, string>
     */
    public function resolveDependencies(array $ids): array
    {
        $maxDepth = max(1, (int) config('arma3-manager.workshop.max_dependency_depth', 4));

        $seen = [];
        $layers = [];
        $frontier = array_values(array_unique(array_filter($ids, WorkshopId::isValid(...))));

        for ($depth = 0; $depth < $maxDepth && $frontier !== []; $depth++) {
            $layers[$depth] = [];
            $next = [];

            foreach ($this->items($frontier) as $id => $item) {
                if (isset($seen[$id])) {
                    continue;
                }

                $seen[$id] = true;
                $layers[$depth][] = $id;

                foreach ($item->children as $child) {
                    if (! isset($seen[$child])) {
                        $next[] = $child;
                    }
                }
            }

            $frontier = array_values(array_unique($next));
        }

        // Deepest layer first: the last frontier reached is the leaf every
        // other layer depends on.
        krsort($layers);

        $ordered = [];

        foreach ($layers as $layer) {
            foreach ($layer as $id) {
                $ordered[] = $id;
            }
        }

        return $ordered;
    }

    private function cacheKey(string $id): string
    {
        return 'a3m:item:' . $id;
    }
}
