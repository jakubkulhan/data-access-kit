<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Fixture;

use DataAccessKit\Repository\Attribute\Repository;

#[Repository(Foo::class)]
interface UpdateTooManyParametersRepositoryInterface
{
	public function updateTwo(Foo $a, Foo $b): void;
}
