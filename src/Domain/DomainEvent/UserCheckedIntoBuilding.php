<?php

namespace Building\Domain\DomainEvent;

use Prooph\EventSourcing\AggregateChanged;

class UserCheckedIntoBuilding extends AggregateChanged
{
  public function username() : string
  {
    return $this->payload['username'];
  }
}