<?php

namespace Building\Domain\DomainEvent;

use Prooph\EventSourcing\AggregateChanged;

class UserCheckedOutOfBuilding extends AggregateChanged
{
  public function username() : string
  {
    return $this->payload['name'];
  }
}