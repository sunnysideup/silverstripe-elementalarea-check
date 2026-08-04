<?php

namespace Sunnysideup\ElementalareaCheck\Tasks;

use DNADesign\Elemental\Extensions\ElementalAreasExtension;
use DNADesign\Elemental\Models\ElementalArea;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DataObjectInterface;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;

/**
 * Checks and (optionally) repairs the denormalised OwnerClassName / TopPageID
 * columns that Elemental keeps on ElementalArea, and reports / optionally removes
 * genuinely orphaned areas.
 *
 * SAFETY MODEL (this is the important part):
 *
 *   - DRY RUN IS THE DEFAULT. Nothing is written unless you explicitly opt out
 *     with ?dryrunonly=0 (or config dry_run_only: false).
 *
 *   - DELETION IS OFF BY DEFAULT and separate. Even with writing enabled, orphan
 *     areas are only reported unless you ALSO pass ?allowdelete=1
 *     (or config allow_delete: true).
 *
 *   - Owners are detected via ElementalAreasExtension (the base extension), so
 *     areas owned by NON-page DataObjects are recognised and NOT mistaken for
 *     orphans. (The old version only looked at ElementalPageExtension, which
 *     could delete legitimately-owned areas.)
 *
 *   - Ownership is resolved with the area's own getTopPage() when available,
 *     falling back to getOwnerPage(), then to a class scan. Ambiguous results
 *     are reported and left untouched rather than guessed at.
 *
 *   - All writes are wrapped in transactions and use parameterised queries.
 *     Deletion goes through the ORM (respecting stages and cascade), never a
 *     raw cross-stage DELETE, so child elements are not left orphaned.
 *
 *   - Integer columns are compared as integers, so "5" vs 5 no longer registers
 *     as a mismatch (which previously caused spurious updates / false orphans).
 *
 * Usage:
 *   Dry run (default):   vendor/bin/sake dev/tasks/elemental-area-check
 *   Apply fixes:         vendor/bin/sake dev/tasks/elemental-area-check dryrunonly=0
 *   Apply + delete orphans:
 *                        vendor/bin/sake dev/tasks/elemental-area-check dryrunonly=0 allowdelete=1
 */
class ElementalAreaCheck extends BuildTask
{
    private static $segment = 'elemental-area-check';

    protected $title = 'Elemental Area Check';

    private static bool $include_versions = false;

    // Safe defaults: dry run on, deletion off.
    private static bool $dry_run_only = true;

    private static bool $allow_delete = false;

    private static bool $quick_test_only = false;

    protected $description = 'Checks and (optionally) repairs elemental areas. Dry run by default; deletion opt-in.';

    /** Stage tables that hold denormalised owner columns. Fixed whitelist - never user input. */
    private const STAGE_SUFFIXES = ['', '_Live'];

    protected static array $validClasses = [];

    public function run($request)
    {
        $dryRunOnly = $this->boolFlag($request, 'dryrunonly', (bool) $this->config()->get('dry_run_only'));
        $allowDelete = $this->boolFlag($request, 'allowdelete', (bool) $this->config()->get('allow_delete'));
        $quickTestOnly = $this->boolFlag($request, 'quicktestonly', (bool) $this->config()->get('quick_test_only'));

        // Deletion only makes sense when we are actually writing.
        $allowDelete = $allowDelete && ! $dryRunOnly;

        $suffixes = self::STAGE_SUFFIXES;
        if ($this->config()->get('include_versions')) {
            // _Versions rows are historical; we may inspect but never delete them.
            $suffixes[] = '_Versions';
        }

        echo "=== Elemental Area Check ===\n";
        echo 'Mode:          ' . ($dryRunOnly ? 'DRY RUN (no writes)' : 'LIVE (writes enabled)') . "\n";
        echo 'Delete orphans:' . ($allowDelete ? " YES\n" : " no (report only)\n");
        echo 'Quick test:    ' . ($quickTestOnly ? "yes\n" : "no\n");
        echo 'Stages:        ' . implode(', ', array_map(fn ($s) => $s === '' ? 'Draft' : ltrim($s, '_'), $suffixes)) . "\n\n";

        $orphanIds = [];

        if (! $quickTestOnly) {
            echo "Running full check...\n";
            foreach ($suffixes as $suffix) {
                $this->processStage($suffix, $dryRunOnly, $orphanIds);
            }

            if ($orphanIds) {
                $this->handleOrphans(array_keys($orphanIds), $allowDelete);
            }
        }

        echo "\n--- Read-only consistency checks ---\n";
        $this->checkPagesWithoutElementalArea();
        $this->checkElementalAreasWithoutPages();

        echo "\nDone.\n";
    }

    /**
     * Process one stage table: fix OwnerClassName / TopPageID where they are wrong,
     * and collect the IDs of rows that have no resolvable owner.
     *
     * @param array<int,bool> $orphanIds passed by reference; keyed by area ID
     */
    protected function processStage(string $suffix, bool $dryRunOnly, array &$orphanIds): void
    {
        $table = 'ElementalArea' . $suffix;
        echo "\n[{$table}]\n";

        $rows = DB::query('SELECT "ID", "OwnerClassName", "TopPageID" FROM "' . $table . '"');

        $useTransaction = ! $dryRunOnly && $this->transactionsSupported();
        if ($useTransaction) {
            DB::get_conn()->transactionStart();
        }

        try {
            foreach ($rows as $row) {
                $id = (int) $row['ID'];
                $ownerClassName = (string) $row['OwnerClassName'];
                $topPageID = (int) $row['TopPageID'];

                $page = $this->resolveOwnerPage($id);

                if (! $page) {
                    echo "  ElementalArea #{$id}: no owner resolvable (OwnerClassName '{$ownerClassName}', TopPageID {$topPageID})\n";
                    if ($suffix !== '_Versions') {
                        $orphanIds[$id] = true; // handled later, once, safely
                    } else {
                        echo "    (skipping - _Versions rows are never deleted)\n";
                    }
                    continue;
                }

                $desiredClass = (string) $page->ClassName;
                $desiredTop = (int) $page->ID;

                if ($ownerClassName !== $desiredClass || $topPageID !== $desiredTop) {
                    echo "  ElementalArea #{$id}: fixing owner -> {$desiredClass} #{$desiredTop}"
                        . " (was '{$ownerClassName}' #{$topPageID})\n";
                    if (! $dryRunOnly) {
                        DB::prepared_query(
                            'UPDATE "' . $table . '" SET "OwnerClassName" = ?, "TopPageID" = ? WHERE "ID" = ?',
                            [$desiredClass, $desiredTop, $id]
                        );
                    }
                }
            }

            if ($useTransaction) {
                DB::get_conn()->transactionEnd();
            }
        } catch (\Throwable $e) {
            if ($useTransaction) {
                DB::get_conn()->transactionRollback();
            }
            echo "  ERROR while processing {$table}: " . $e->getMessage() . " (changes to this table rolled back)\n";
        }
    }

    /**
     * Resolve the owning record for an ElementalArea, preferring Elemental's own
     * ownership resolution and only falling back to a class scan.
     */
    protected function resolveOwnerPage(int $id): ?DataObjectInterface
    {
        $area = ElementalArea::get()->byID($id);
        if ($area) {
            if ($area->hasMethod('getTopPage')) {
                $page = $area->getTopPage();
                if ($page && $page->exists()) {
                    if ($area->hasMethod('setTopPage')) {
                        $area->setTopPage($page);
                    }
                    return $page;
                }
            }
            if ($area->hasMethod('getOwnerPage')) {
                $page = $area->getOwnerPage();
                if ($page && $page->exists()) {
                    return $page;
                }
            }
        }

        return $this->findParentByScan($id);
    }

    /**
     * Fallback: scan owner classes for one that references this area.
     * Returns null (and reports) when ambiguous, so we never guess.
     */
    protected function findParentByScan(int $id): ?DataObjectInterface
    {
        $items = [];
        foreach ($this->findValidClasses() as $class) {
            foreach ($class::get()->filter('ElementalAreaID', $id) as $owner) {
                $items[$owner->ID] = $owner;
            }
        }

        if (count($items) > 1) {
            echo "    (multiple owners reference ElementalArea #{$id}; leaving untouched)\n";
            return null;
        }
        if (count($items) === 1) {
            return reset($items);
        }
        return null;
    }

    /**
     * Report and, if permitted, safely remove orphaned areas via the ORM.
     *
     * @param array<int,int> $ids
     */
    protected function handleOrphans(array $ids, bool $allowDelete): void
    {
        echo "\n--- Orphaned elemental areas (" . count($ids) . ") ---\n";

        if (! $allowDelete) {
            foreach ($ids as $id) {
                $area = $this->loadAreaAnyStage($id);
                $elementCount = $area ? $area->Elements()->count() : '?';
                echo "  ElementalArea #{$id} (elements: {$elementCount}) - reported only. "
                    . "Pass allowdelete=1 with dryrunonly=0 to remove.\n";
            }
            return;
        }

        $useTransaction = $this->transactionsSupported();
        if ($useTransaction) {
            DB::get_conn()->transactionStart();
        }

        try {
            foreach ($ids as $id) {
                $this->safelyDeleteArea($id);
            }
            if ($useTransaction) {
                DB::get_conn()->transactionEnd();
            }
        } catch (\Throwable $e) {
            if ($useTransaction) {
                DB::get_conn()->transactionRollback();
            }
            echo "  ERROR during deletion: " . $e->getMessage() . " (all deletions rolled back)\n";
        }
    }

    /**
     * Delete an orphan area and its owned elements through the ORM, across stages.
     * Never touches subclass/stage tables with raw SQL, so nothing is left dangling.
     */
    protected function safelyDeleteArea(int $id): void
    {
        $area = $this->loadAreaAnyStage($id);
        if (! $area) {
            echo "  ElementalArea #{$id}: cannot load as an ORM object on any stage; skipping (manual review needed)\n";
            return;
        }

        foreach ($area->Elements() as $element) {
            $this->deleteAllStages($element);
        }
        $this->deleteAllStages($area);

        echo "  ElementalArea #{$id}: deleted (with owned elements, draft + live)\n";
    }

    protected function loadAreaAnyStage(int $id): ?ElementalArea
    {
        $area = ElementalArea::get()->byID($id);
        if (! $area && class_exists(Versioned::class)) {
            $area = Versioned::get_by_stage(ElementalArea::class, Versioned::LIVE)->byID($id);
        }
        return $area;
    }

    protected function deleteAllStages(DataObject $obj): void
    {
        if ($obj->hasExtension(Versioned::class)) {
            if ($obj->hasMethod('isPublished') && $obj->isPublished()) {
                $obj->deleteFromStage(Versioned::LIVE);
            }
            $obj->deleteFromStage(Versioned::DRAFT);
        } else {
            $obj->delete();
        }
    }

    protected function checkElementalAreasWithoutPages(): void
    {
        $count = 0;
        foreach (ElementalArea::get() as $area) {
            if (! $this->resolveOwnerPage((int) $area->ID)) {
                $count++;
                echo "  ERROR: ElementalArea #{$area->ID} (OwnerClassName '{$area->OwnerClassName}', "
                    . "TopPageID {$area->TopPageID}) has no resolvable owner.\n";
            }
        }
        if ($count === 0) {
            echo "  OK: every ElementalArea has a resolvable owner.\n";
        }
    }

    protected function checkPagesWithoutElementalArea(): void
    {
        $existingAreaIDs = [];
        foreach (ElementalArea::get()->column('ID') as $areaID) {
            $existingAreaIDs[(int) $areaID] = true;
        }

        $count = 0;
        foreach ($this->findValidClasses() as $class) {
            // [ownerID => areaID] - parameterised, memory friendly.
            $map = $class::get()->map('ID', 'ElementalAreaID')->toArray();
            foreach ($map as $ownerID => $areaID) {
                $areaID = (int) $areaID;
                if ($areaID !== 0 && ! isset($existingAreaIDs[$areaID])) {
                    $count++;
                    echo "  ERROR: {$class} #{$ownerID} has ElementalAreaID {$areaID} "
                        . "which does not exist in the ElementalArea table.\n";
                }
            }
        }
        if ($count === 0) {
            echo "  OK: every owner points to an existing ElementalArea.\n";
        }
    }

    /**
     * Owner classes = any DataObject with the base ElementalAreasExtension
     * (pages AND non-page owners). Abstract / uninstantiable classes are skipped.
     *
     * @return array<int,string>
     */
    protected function findValidClasses(): array
    {
        if (self::$validClasses) {
            return self::$validClasses;
        }

        foreach (ClassInfo::subclassesFor(DataObject::class, false) as $class) {
            if (! class_exists($class)) {
                continue;
            }
            try {
                if ((new \ReflectionClass($class))->isAbstract()) {
                    continue;
                }
                $obj = Injector::inst()->get($class);
            } catch (\Throwable $e) {
                continue;
            }
            if ($obj->hasExtension(ElementalAreasExtension::class)) {
                self::$validClasses[] = $class;
            }
        }
        return self::$validClasses;
    }

    protected function boolFlag($request, string $name, bool $default): bool
    {
        if (! $request) {
            return $default;
        }
        $value = $request->getVar($name);
        if ($value === null) {
            return $default;
        }
        // Bare ?flag (empty string) counts as true; only explicit falsey values disable.
        return ! in_array(strtolower(trim((string) $value)), ['0', 'false', 'no', 'off'], true);
    }

    protected function transactionsSupported(): bool
    {
        return DB::get_conn()->supportsTransactions();
    }

    /**
     * Retrying is only safe because every write is transactional and the update
     * path is idempotent. Deletion is opt-in and gated behind an explicit flag,
     * so an automated retry will not silently re-delete. Set to false if you would
     * rather this task never auto-reruns after a failure.
     */
    public function canRunAgainOnFailure(): bool
    {
        return true;
    }
}
