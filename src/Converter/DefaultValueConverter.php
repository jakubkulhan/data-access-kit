<?php declare(strict_types=1);

namespace DataAccessKit\Converter;

use DataAccessKit\Attribute\Column;
use DataAccessKit\Attribute\Table;
use DataAccessKit\Exception\ConverterException;
use DataAccessKit\Registry;
use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use ReflectionNamedType;
use stdClass;
use function in_array;
use function is_object;
use function json_decode;
use function json_encode;
use function spl_object_id;
use function sprintf;

class DefaultValueConverter implements ValueConverterInterface
{
	/** @var array<int, bool> */
	private array $recursionGuard = [];

	public function __construct(
		private readonly Registry $registry,
		private readonly DateTimeZone $dateTimeZone = new DateTimeZone("UTC"),
		private readonly string $dateTimeFormat = "Y-m-d H:i:s",
	)
	{
	}

	public function objectToDatabase(Table $table, Column $column, mixed $value, bool $encode = true): mixed
	{
		if ($value === null) {
			return null;
		}

		$recursionGuardKey = null;
		if (is_object($value)) {
			$recursionGuardKey = spl_object_id($value);
			if (isset($this->recursionGuard[$recursionGuardKey])) {
				throw new ConverterException("Recursion detected.");
			}
			$this->recursionGuard[$recursionGuardKey] = true;
		}
		try {
			$valueType = $column->reflection->getType();
			if ($valueType instanceof ReflectionNamedType) {
				if (in_array($valueType->getName(), ["int", "float", "string", "bool"], true)) {
					$returnValue = $value;
				} else if ($valueType->getName() === "object") {
					$returnValue = $encode ? json_encode($value) : $value;
				} else if ($valueType->getName() === "array") {
					if ($column->itemType === null) {
						$returnValue = $encode ? json_encode($value) : $value;
					} else {
						$nestedTable = $this->registry->get($column->itemType);
						$jsonArray = [];
						foreach ($value as $item) {
							$jsonArray[] = $jsonObject = new stdClass();
							foreach ($nestedTable->columns as $nestedColumn) {
								$jsonObject->{$nestedColumn->name} = $this->objectToDatabase(
									$nestedTable,
									$nestedColumn,
									$nestedColumn->reflection->getValue($item),
									false,
								);
							}
						}
						$returnValue = $encode ? json_encode($jsonArray) : $jsonArray;
					}
				} else if (in_array($valueType->getName(), [DateTime::class, DateTimeImmutable::class], true)) {
					/** @var DateTime|DateTimeImmutable $value */
					$returnValue = (clone $value)->setTimezone($this->dateTimeZone)->format($this->dateTimeFormat);
				} else if (null !== ($nestedTable = $this->registry->maybeGet($valueType->getName()))) {
					$jsonObject = new stdClass();
					foreach ($nestedTable->columns as $nestedColumn) {
						$jsonObject->{$nestedColumn->name} = $this->objectToDatabase(
							$nestedTable,
							$nestedColumn,
							$nestedColumn->reflection->getValue($value),
							false,
						);
					}
					$returnValue = $encode ? json_encode($jsonObject) : $jsonObject;
				} else {
					throw new ConverterException(sprintf(
						"Unsupported type [%s] of property [%s::\$%s].",
						$valueType->getName(),
						$table->reflection->getName(),
						$column->reflection->getName()
					));
				}
			} else {
				throw new ConverterException(sprintf(
					"Property [%s::\$%s] must have a named type declaration (union and intersect declarations are not supported).",
					$table->reflection->getName(),
					$column->reflection->getName()
				));
			}

			return $returnValue;

		} finally {
			if ($recursionGuardKey !== null) {
				unset($this->recursionGuard[$recursionGuardKey]);
			}
		}
	}

	public function databaseToObject(Table $table, Column $column, mixed $value, bool $decode = true): mixed
	{
		if ($value === null) {
			return null;
		}

		$valueType = $column->reflection->getType();
		if ($valueType instanceof ReflectionNamedType) {
			if (in_array($valueType->getName(), ["int", "float", "string", "bool"], true)) {
				$returnValue = $value;
			} else if ($valueType->getName() === "object") {
				$returnValue = $decode ? json_decode($value) : $value;
			} else if ($valueType->getName() === "array") {
				if ($column->itemType === null) {
					$returnValue = $decode ? json_decode($value) : $value;
				} else {
					$nestedTable = $this->registry->get($column->itemType);
					$array = [];
					foreach ($decode ? json_decode($value) : $value as $jsonObject) {
						$nestedObject = $nestedTable->reflection->newInstanceWithoutConstructor();
						foreach ($nestedTable->columns as $nestedColumn) {
							$nestedColumn->reflection->setValue(
								$nestedObject,
								$this->databaseToObject(
									$nestedTable,
									$nestedColumn,
									$jsonObject->{$nestedColumn->name},
									false,
								),
							);
						}
						$array[] = $nestedObject;
					}
					$returnValue = $array;
				}
			} else if ($valueType->getName() === DateTime::class) {
				$returnValue = DateTime::createFromFormat($this->dateTimeFormat, $value, $this->dateTimeZone);
			} else if ($valueType->getName() === DateTimeImmutable::class) {
				$returnValue = DateTimeImmutable::createFromFormat($this->dateTimeFormat, $value, $this->dateTimeZone);
			} else if (null !== ($nestedTable = $this->registry->maybeGet($valueType->getName()))) {
				$jsonObject = $decode ? json_decode($value) : $value;
				$nestedObject = $nestedTable->reflection->newInstanceWithoutConstructor();
				foreach ($nestedTable->columns as $nestedColumn) {
					$nestedColumn->reflection->setValue(
						$nestedObject,
						$this->databaseToObject(
							$nestedTable,
							$nestedColumn,
							$jsonObject->{$nestedColumn->name},
							false,
						),
					);
				}
				$returnValue = $nestedObject;
			} else {
				throw new ConverterException(sprintf(
					"Unsupported type [%s] of property [%s::\$%s].",
					$valueType->getName(),
					$table->reflection->getName(),
					$column->reflection->getName()
				));
			}
		} else {
			throw new ConverterException(sprintf(
				"Property [%s::\$%s] must have a named type declaration (union and intersect declarations are not supported).",
				$table->reflection->getName(),
				$column->reflection->getName()
			));
		}

		return $returnValue;
	}
}
