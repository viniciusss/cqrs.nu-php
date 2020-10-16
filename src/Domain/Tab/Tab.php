<?php

declare(strict_types=1);

namespace Cafe\Domain\Tab;

use Cafe\Application\Write\MarkDrinksServed;
use Cafe\Application\Write\OpenTabCommand;
use Cafe\Application\Write\PlaceOrderCommand;
use Cafe\Domain\Tab\Events\TabClosed;
use Cafe\Domain\Tab\Events\TabOpened;
use Cafe\Domain\Tab\Events\DrinksOrdered;
use Cafe\Domain\Tab\Events\DrinksServed;
use Cafe\Domain\Tab\Events\FoodOrdered;
use Cafe\Domain\Tab\Exception\DrinksNotOutstanding;
use EventSauce\EventSourcing\AggregateRoot;
use EventSauce\EventSourcing\AggregateRootBehaviour;

final class Tab implements AggregateRoot
{
    use AggregateRootBehaviour;

    private bool $open = false;
    private array $outstandingDrinks = [];
    private array $outstandingFood = [];
    private array $preparedFood = [];
    private float $servedItemsValue = 0.0;

    public static function open(OpenTabCommand $command) : self
    {
        $tab = new static($command->tabId);

        $tab->recordThat(new TabOpened(
            $command->tabId,
            $command->tableNumber,
            $command->waiter)
        );

        return $tab;
    }

    public function order(PlaceOrderCommand $command) : void
    {
        if ($command->hasDrinks()) {
            $this->recordThat(new DrinksOrdered($command->tabId, $command->getDrinks()));
        }

        if ($command->hasFood()) {
            $this->recordThat(new FoodOrdered($command->tabId, $command->getFood()));
        }
    }

    public function markDrinksServed(MarkDrinksServed $command) : void
    {
        if (!$this->areDrinksOutstanding($command->menuNumbers)) {
            throw new DrinksNotOutstanding();
        }

        $this->recordThat(new DrinksServed($command->tabId, $command->menuNumbers));
    }

    public function applyTabOpened(TabOpened $event): void
    {
        $this->open = true;
    }

    public function applyDrinksOrdered(DrinksOrdered $event): void
    {
        $this->outstandingDrinks += $event->items;
    }

    public function applyFoodOrdered(FoodOrdered $event) : void
    {
        $this->outstandingFood[] = $event->items;
    }

    public function applyDrinksServed(DrinksServed $event) : void
    {
        foreach ($event->menuNumbers as $num) {
            /** @var OrderedItem $item */
            $item = array_values(array_filter($this->outstandingDrinks, fn(OrderedItem $drink) => $drink->menuNumber === $num))[0];

            if (($position = array_search($item, $this->outstandingDrinks, true)) !== false) {
                unset($this->outstandingDrinks[$position]);
            }

            $this->servedItemsValue += $item->price;
        }
    }

    public function applyTabClosed(TabClosed $event) : void
    {
        $this->open = false;
    }

    /**
     * @param array<int> $menuNumbers
     */
    private function areDrinksOutstanding(array $menuNumbers) : bool
    {
        return $this->areAllInList($menuNumbers, $this->outstandingDrinks);
    }

    /**
     * @param array<int> $want
     * @param array<OrderedItem> $have
     */
    private function areAllInList(array $want, array $have): bool
    {
        $curHave = array_map(fn(OrderedItem $orderedItem) => $orderedItem->menuNumber, $have);
        foreach ($want as $num) {
            if (($key = array_search($num, $curHave, true)) !== false) {
                unset($curHave[$key]);
            } else {
                return false;
            }
        }

        return true;
    }
}