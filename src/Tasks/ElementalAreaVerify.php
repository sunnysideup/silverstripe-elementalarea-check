<?php

namespace Sunnysideup\ElementalareaCheck\Tasks;

use DNADesign\Elemental\Extensions\ElementalAreasExtension;
use DNADesign\Elemental\Models\ElementalArea;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DataObject;

/**
 * READ-ONLY verification task. Makes NO changes to the database.
 *
 * Reports two kinds of inconsistency:
 *
 *   1. Owners without a (valid) elemental area:
 *        a. owner has ElementalAreaID = 0  (no area assigned at all)
 *        b. owner points to an ElementalArea ID that does not exist (dangling)
 *
 *   2. Elemental areas without an owner (orphans):
 *        an ElementalArea row that no owner record references.
 *
 * "Owner" = any DataObject with ElementalAreasExtension, which correctly
 * includes non-page owners (the original task only looked at pages via
 * ElementalPageExtension, so it treated non-page-owned areas as orphans).
 *
 * Run:
 *   vendor/bin/sake dev/tasks/elemental-area-verify
 *   or visit /dev/tasks/elemental-area-verify?flush=1 in the browser.
 */
class ElementalAreaVerify extends BuildTask
{
    private static $segment = 'elemental-area-verify';

    protected $title = 'Elemental Area Verify (read-only)';

    protected $description = 'Reports pages/owners without an elemental area, and elemental areas without an owner. Makes no changes.';

    public function run($request)
    {
        // 1. Discover every (ownerClass, relationField) that points to an ElementalArea.
        $relations = $this->findOwnerRelations();
        if (empty($relations)) {
            echo "No classes with ElementalAreasExtension were found. Nothing to check.\n";
            return;
        }

        echo "Owner relations being checked (stage: current reading stage):\n";
        foreach ($relations as [$class, $field]) {
            echo "  - {$class}.{$field}\n";
        }
        echo "\n";

        // 2. Build the set of ElementalArea IDs that actually exist.
        $existingAreaIDs = [];
        foreach (ElementalArea::get()->column('ID') as $id) {
            $existingAreaIDs[(int) $id] = true;
        }
        echo 'Total ElementalArea records: ' . count($existingAreaIDs) . "\n\n";

        // 3. Walk every owner. Collect problems and the set of referenced area IDs.
        $referencedAreaIDs = [];
        $missingArea = [];   // owner has ElementalAreaID = 0
        $danglingArea = [];  // owner points to a non-existent area

        foreach ($relations as [$class, $field]) {
            // map('ID', $field) => [ownerID => areaID], parameterised and memory-friendly.
            $map = $class::get()->map('ID', $field)->toArray();
            foreach ($map as $ownerID => $areaID) {
                $areaID = (int) $areaID;
                if ($areaID === 0) {
                    $missingArea[] = "{$class} #{$ownerID} ({$field} = 0)";
                    continue;
                }
                $referencedAreaIDs[$areaID] = true;
                if (! isset($existingAreaIDs[$areaID])) {
                    $danglingArea[] = "{$class} #{$ownerID} ({$field} = {$areaID}) -> area does not exist";
                }
            }
        }

        // 4. Orphans: areas that exist but that no owner references.
        $orphanAreaIDs = array_diff(array_keys($existingAreaIDs), array_keys($referencedAreaIDs));

        // ---- Report --------------------------------------------------------
        echo "=== Owners with NO elemental area (ID = 0) ===\n";
        $this->report($missingArea);

        echo "\n=== Owners pointing to a MISSING elemental area (dangling reference) ===\n";
        $this->report($danglingArea);

        echo "\n=== Elemental areas with NO owner (orphans) ===\n";
        if (empty($orphanAreaIDs)) {
            echo "  None. Every elemental area is referenced by an owner.\n";
        } else {
            foreach ($orphanAreaIDs as $areaID) {
                $area = ElementalArea::get()->byID($areaID);
                $owner = $area ? ($area->OwnerClassName ?: '(blank)') : '?';
                $top = $area ? ($area->TopPageID ?: '(blank)') : '?';
                $elementCount = $area ? $area->Elements()->count() : 0;
                echo "  - ElementalArea #{$areaID} (OwnerClassName: {$owner}, TopPageID: {$top}, elements: {$elementCount})\n";
            }
        }

        // ---- Summary -------------------------------------------------------
        echo "\n=== Summary ===\n";
        echo 'Owners with no area:       ' . count($missingArea) . "\n";
        echo 'Owners with dangling area: ' . count($danglingArea) . "\n";
        echo 'Orphan areas:              ' . count($orphanAreaIDs) . "\n";

        $clean = empty($missingArea) && empty($danglingArea) && empty($orphanAreaIDs);
        echo "\n" . ($clean
            ? "RESULT: All consistent. The previous task appears to have done its job.\n"
            : "RESULT: Inconsistencies found (see above).\n");
    }

    /**
     * Find every relation that points from an owner to an ElementalArea.
     *
     * @return array<int, array{0:string,1:string}> list of [ownerClass, relationField]
     */
    protected function findOwnerRelations(): array
    {
        $ownerFields = [];   // class => [field, ...]

        foreach (ClassInfo::subclassesFor(DataObject::class, false) as $class) {
            if (! class_exists($class)) {
                continue;
            }
            if ((new \ReflectionClass($class))->isAbstract()) {
                continue;
            }
            $obj = Injector::inst()->get($class);
            if (! $obj->hasExtension(ElementalAreasExtension::class)) {
                continue;
            }
            $hasOne = Config::inst()->get($class, 'has_one') ?: [];
            foreach ($hasOne as $relName => $relSpec) {
                // Skip BaseElement's "Parent" relation: it points UP to the area that
                // contains the element, i.e. the element does not *own* that area.
                if ($relName === 'Parent') {
                    continue;
                }
                $target = is_array($relSpec) ? ($relSpec['class'] ?? null) : $relSpec;
                if (! $target) {
                    continue;
                }
                if ($target === ElementalArea::class || is_subclass_of($target, ElementalArea::class)) {
                    $ownerFields[$class][] = $relName . 'ID';
                }
            }
        }

        // Keep only the top-most class in each hierarchy. Querying it with ::get()
        // already returns all subclasses, so this avoids double-counting records.
        $relations = [];
        foreach ($ownerFields as $class => $fields) {
            foreach (array_unique($fields) as $field) {
                if (! $this->hasAncestorWithField($class, $field, $ownerFields)) {
                    $relations[] = [$class, $field];
                }
            }
        }
        return $relations;
    }

    protected function hasAncestorWithField(string $class, string $field, array $ownerFields): bool
    {
        $parent = get_parent_class($class);
        while ($parent) {
            if (isset($ownerFields[$parent]) && in_array($field, $ownerFields[$parent], true)) {
                return true;
            }
            $parent = get_parent_class($parent);
        }
        return false;
    }

    protected function report(array $items): void
    {
        if (empty($items)) {
            echo "  None.\n";
            return;
        }
        foreach ($items as $line) {
            echo "  - {$line}\n";
        }
    }
}
