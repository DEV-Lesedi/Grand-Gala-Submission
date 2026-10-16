<?php

declare(strict_types=1);

namespace GrandGala\Tests\Unit;

use GrandGala\Models\SpaceRate;
use GrandGala\Services\Rates;
use GrandGala\Tests\Support\DatabaseTestCase;
use GrandGala\Utils\Db;

// Two staff change the same price at once. second_staff.php is the second person.
// Without a lock both would close the same old price, leaving two open normal prices.
class RatesTwoAtOnceTest extends DatabaseTestCase
{
    public function testTwoPriceChangesAtOnceLeaveOneOpenPrice(): void
    {
        $spaceId = $this->createSpace();
        $this->addRate($spaceId, 'per_night', 2800, '2026-01-01', null);

        $secondStaff = null;
        // The & fills in $secondStaff, so we can use it after the transaction.
        Db::transaction(function () use ($spaceId, &$secondStaff) {
            // The first staff member adds a new price. It's saved when this transaction ends.
            $rate = Rates::add($spaceId, 'per_night', 'Standard', 3000, '2026-10-01', null);
            $this->track('space_rate', 'sr_id', $rate['id']);

            // The second changes the price before the first has saved, so it has to wait
            $secondStaff = $this->startScript('second_staff.php', [$spaceId, '2026-11-01', 3200]);
            sleep(3);
        });

        $this->track('space_rate', 'sr_id', (int) $this->waitForScript($secondStaff));

        $open = [];
        foreach (SpaceRate::forSpaceBasis($spaceId, 'per_night') as $rate) {
            if ($rate['isActive'] && $rate['validTo'] === null) {
                $open[] = $rate['price'];
            }
        }
        self::assertSame([3200.0], $open);
    }
}
