<?php

namespace Sunnysideup\ElementalareaCheck\Tasks;

use DNADesign\Elemental\Extensions\ElementalPageExtension;
use DNADesign\Elemental\Models\ElementalArea;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DataObjectInterface;
use SilverStripe\ORM\DB;
use Sunnysideup\DatabaseMigrations\Interfaces\AtomicMigrationInterface;

class ElementalAreaCheck extends BuildTask
{
    private static $segment = 'elemental-area-check';

    protected $title = 'Elemental Area Check';

    protected $description = 'Checks and updates elemental areas on pages';

    public function run($request)
    {
        foreach (['', '_Live', '_Versions'] as $suffix) {
            $rows = DB::query('SELECT "ID", "OwnerClassName", "TopPageID" FROM "ElementalArea'.$suffix.'"');
            foreach ($rows as $row) {
                $id = $row['ID'];
                $ownerClassName = $row['OwnerClassName'];
                $topPageID = $row['TopPageID'];
                $page = SiteTree::get()->filter('ID', $topPageID)->first();
                if ($page && $page->ElementalAreaID !== $id) {
                    echo "Found mismatch for ElementalArea ID $id: TopPageID $topPageID has ElementalAreaID {$page->ElementalAreaID}\n";
                    $page = $this->findParent($id);
                } elseif (! $page) {
                    echo "No page found with ID $topPageID for ElementalArea ID $id\n";
                    $page = $this->findParent($id);
                }
                if ($page) {
                    if ($page->ClassName !== $ownerClassName) {
                        echo "Updating ElementalArea ID $id: OwnerClassName set to $page->ClassName\n";
                        DB::query("UPDATE \"ElementalArea".$suffix."\" SET \"OwnerClassName\" = '".addslashes($page->ClassName)."', \"TopPageID\" = $page->ID WHERE \"ID\" = $id");
                    } elseif ($page->ID !== $topPageID) {
                        echo "Updating ElementalArea ID $id: TopPageID set to $page->ID\n";
                        DB::query("UPDATE \"ElementalArea".$suffix."\" SET \"TopPageID\" = $page->ID WHERE \"ID\" = $id");
                    } else {
                        echo "OK\n";
                    }
                } else {
                    echo "No page found for ElementalArea ID $id with OwnerClassName $ownerClassName and TopPageID $topPageID\n";
                    if ($suffix !== '_Versions') {
                        echo "Deleting ElementalArea ID $id from table ElementalArea$suffix\n";
                        DB::query("DELETE FROM \"ElementalArea".$suffix."\" WHERE \"ID\" = $id");
                    } else {
                        echo "Skipping deletion of ElementalArea ID $id from Versions table\n";
                    }
                }
            }
        }
        $this->checkPagesWithoutElementalArea();

    }

    /**
     * Return true if this task should run again after a failure
     * Return false if it should only run once (even if it failed)
     */
    public function canRunAgainOnFailure(): bool
    {
        // Return true if safe to retry on failure
        return true;

        // Return false if the task made partial changes
        // and re-running could cause data corruption
        // return false;
    }

    protected static array $validClasses = [];

    protected function findParent(int $id): ?DataObjectInterface
    {
        $classes = $this->findValidClasses();

        $items = [];
        foreach ($classes as $class) {
            $obj = Injector::inst()->get($class);
            if ($obj->hasExtension(ElementalPageExtension::class)) {
                $pages = $class::get()->filter('ElementalAreaID', $id);
                foreach ($pages as $page) {
                    $items[$page->ID] = $page;
                }
            }
        }
        if (count($items) > 1) {
            echo "Multiple pages found for ElementalArea ID $id:\n";
        } elseif (count($items) === 1) {
            return reset($items);
        } else {
            echo "No pages found for ElementalArea ID $id\n";
        }
        return null;
    }

    protected function checkPagesWithoutElementalArea(): array
    {
        $pagesWithoutElementalArea = [];
        foreach ($this->findValidClasses() as $class) {
            $pages = $class::get();
            $uniqueElementalAreaIDsFromPages = $pages->columnUnique('ElementalAreaID');
            $uniqueElementalAreaIDsFromObjects = ElementalArea::get()->columnUnique('ID');
            $uniqueElementalAreaIDs = array_diff($uniqueElementalAreaIDsFromPages, $uniqueElementalAreaIDsFromObjects);
            $pages = $pages->filter('ElementalAreaID', $uniqueElementalAreaIDs);
            foreach ($pages as $page) {
                $pagesWithoutElementalArea[] = $page;
                echo "Page ID {$page->ID} of class {$page->ClassName} has ElementalAreaID {$page->ElementalAreaID} which does not exist in ElementalArea table.\n";
            }
        }
        return $pagesWithoutElementalArea;
    }

    protected function findValidClasses(): array
    {
        $classes = ClassInfo::subclassesFor(DataObject::class, false);
        if (count(self::$validClasses)) {
            return self::$validClasses;
        }
        foreach ($classes as $class) {
            $obj = Injector::inst()->get($class);
            if ($obj->hasExtension(ElementalPageExtension::class)) {
                self::$validClasses[] = $class;
            }
        }
        return self::$validClasses;
    }
}
