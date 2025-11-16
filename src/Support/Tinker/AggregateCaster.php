<?php
// @codeCoverageIgnoreStart

namespace Pillar\Support\Tinker;

use Pillar\Aggregate\AggregateRootId;
use Pillar\Aggregate\EventSourcedAggregateRoot;
use Symfony\Component\VarDumper\Caster\Caster;
use Symfony\Component\VarDumper\Cloner\Stub;

final class AggregateCaster
{
    /**
     * @param EventSourcedAggregateRoot $aggregate
     * @param array $a Original object properties
     * @param Stub $stub
     * @param bool $isNested
     * @return array
     */
    public static function castAggregate(
        EventSourcedAggregateRoot $aggregate,
        array                     $a,
        Stub                      $stub,
        bool                      $isNested
    ): array
    {
        // We return a compact, Tinker-friendly summary.
        $out = [];

        // Virtual id
        if (method_exists($aggregate, 'id')) {
            $id = $aggregate->id();

            if ($id instanceof AggregateRootId) {
                $id = (string)$id;
            }

            $out[Caster::PREFIX_VIRTUAL . 'id'] = $id;
        }

        foreach ($a as $key => $value) {
            $name = $key;
            if (($pos = strrpos($name, "\0")) !== false) {
                $name = substr($name, $pos + 1);
            }
            if ($name === 'id' || $name === 'recordedEvents') {
                continue;
            }
            $out[Caster::PREFIX_VIRTUAL . $name] = $value;
        }

        if (method_exists($aggregate, 'recordedEvents')) {
            $events = $aggregate->recordedEvents();

            if (!empty($events)) {
                $out[Caster::PREFIX_VIRTUAL . 'recordedEvents'] = array_map(
                    static fn(object $e): string => $e::class,
                    $events,
                );
            }
        }

        return $out;
    }
}
// @codeCoverageIgnoreEnd