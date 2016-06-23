<?php

namespace Building\Domain\Aggregate;

use Building\Domain\DomainEvent\NewBuildingWasRegistered;
use Building\Domain\DomainEvent\UserCheckedIntoBuilding;
use Building\Domain\DomainEvent\UserCheckedOutOfBuilding;
use Prooph\EventSourcing\AggregateRoot;
use Rhumsaa\Uuid\Uuid;

final class Building extends AggregateRoot
{
    /**
     * @var Uuid
     */
    private $uuid;

    /**
     * @var string
     */
    private $name;

    /**
     * @var string[]
     */
    private $checkedInUsers = [];

    public static function new($name) : self
    {
        $self = new self();

        $self->recordThat(NewBuildingWasRegistered::occur(
            (string) Uuid::uuid4(),
            [
                'name' => $name
            ]
        ));

        return $self;
    }

    public function checkInUser(string $username)
    {
        /*
        if(in_array($username, $this->checkedInUsers, true)) {
            throw new \InvalidArgumentException(sprintf(
                'User "%S" is already checked in',
                $username
            ));
        }
        */
        $this->recordThat(UserCheckedIntoBuilding::occur(
            $this->id(),
          [
              'name' => $username,
          ]
        ));
    }

    public function checkOutUser(string $username)
    {
        $this->recordThat(UserCheckedOutOfBuilding::occur(
          $this->id(),
          [
              'name' => $username,
          ]
        ));
    }

    public function whenNewBuildingWasRegistered(NewBuildingWasRegistered $event)
    {
        $this->uuid = $event->uuid();
        $this->name = $event->name();
    }

    public function whenUserCheckedIntoBuilding(UserCheckedIntoBuilding $event)
    {
        $username = $event->username();

        $this->checkedInUsers[] = $username;
        $this->checkedInUsers = array_unique($this->checkedInUsers);
    }

    public function whenUserCheckedOutOfBuilding(UserCheckedOutOfBuilding $event)
    {
        //
    }

    /**
     * {@inheritDoc}
     */
    protected function aggregateId() : string
    {
        return (string) $this->uuid;
    }

    /**
     * {@inheritDoc}
     */
    public function id() : string
    {
        return $this->aggregateId();
    }
}
