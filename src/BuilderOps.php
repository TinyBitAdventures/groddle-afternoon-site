<?php

declare(strict_types=1);

namespace Groddle\Minn;

/**
 * The level builder's op vocabulary (the game's docs/level-builder/spec.md 6.6 and 8.3), a
 * line-for-line port of the game's public/js/builder/ops.js: the store applies the same ops the
 * editor and an agent send, and the game's tests/builder-ops.test.js runs one script of ops
 * through both and compares the bytes. A zone is held as json_decode gives it without $assoc
 * (objects stay stdClass), so {} and [] come back as they went.
 *
 * Key order is kept as ops.js keeps it: an inverse that puts back a removed field carries
 * `order`, the object's keys as they were (reorder).
 *
 * Also the store's cheap structural check (spec 7.1: JSON shape, sizes and limits). The physics
 * and the schema proper are the validator's, in the game.
 */
final class BuilderOps
{
    public const STREET_FIELDS = ['label', 'internalLabel', 'hubId', 'hubName', 'gradient', 'musicFile', 'noMap', 'fogMap', 'bounds'];
    public const LAYER_FIELDS = ['name', 'z', 'w', 'h', 'hidden', 'filters'];
    public const DECO_FIELDS = ['sprite', 'name', 'signText', 'x', 'y', 'w', 'h', 'z', 'r', 'flip', 'hidden', 'animated'];
    private const ZONE_FIELDS = ['title', 'notes'];

    /** Geometry kinds: the street list each lives in, its id prefix and the fields an op may set. */
    public const GEOMETRY = [
        'platform' => ['list' => 'platforms', 'prefix' => 'plat_', 'fields' => ['x1', 'y1', 'x2', 'y2', 'pc_perm', 'item_perm']],
        'barrier' => ['list' => 'barriers', 'prefix' => 'wall_', 'fields' => ['x', 'y', 'h', 'pc_perm']],
        'ladder' => ['list' => 'ladders', 'prefix' => 'ladder_', 'fields' => ['x', 'y', 'w', 'h', 'tiling']],
        'signpost' => ['list' => 'signposts', 'prefix' => 'signpost_', 'fields' => ['x', 'y', 'w', 'h']],
    ];
    public const CONNECT_FIELDS = ['tsid', 'label', 'hub_id', 'hidden', 'arrive'];
    /** A zone edit's fields (schema $defs/streetEdit) and its two kinds. */
    public const EDIT_FIELDS = ['tsid', 'op', 'signpost', 'connect', 'newSignpost'];
    public const EDIT_KINDS = ['add-connect', 'add-signpost'];
    /** Fields whose null is a value (a perm of null is hard both ways), not a missing value. */
    private const NULLABLE = ['pc_perm', 'item_perm'];

    /** Spec section 12. */
    public const MAX_ZONE_BYTES = 4194304;
    public const MAX_OPS = 500;
    public const MAX_STREETS = 40;
    public const MAX_LAYERS = 24;
    public const MAX_DECOS = 1500;
    public const MAX_PLATFORMS = 600;

    /** The JSON the store writes and compares: slashes and Unicode as they are. */
    public static function encode(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** JSON to a zone or op tree (objects as stdClass), or null when it is not JSON. */
    public static function decode(string $json): mixed
    {
        try {
            return json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }

    private static function copy(mixed $v): mixed
    {
        return json_decode(self::encode($v), false);
    }

    private static function need(bool $cond, string $code, string $message): void
    {
        if (!$cond) {
            throw new BuilderOpError($code, $message);
        }
    }

    private static function isObj(mixed $v): bool
    {
        return $v instanceof \stdClass;
    }

    private static function has(object $o, string $k): bool
    {
        return property_exists($o, $k);
    }

    private static function prop(object $o, string $k): mixed
    {
        return property_exists($o, $k) ? $o->{$k} : null;
    }

    public static function streetRow(\stdClass $zone, mixed $key): \stdClass
    {
        foreach (is_array($zone->streets ?? null) ? $zone->streets : [] as $row) {
            if (self::isObj($row) && ($row->key ?? null) === $key) {
                return $row;
            }
        }
        throw new BuilderOpError('builder_no_street', 'No street "' . (is_string($key) ? $key : '') . '" in this draft.');
    }

    /** @return array{0: ?\stdClass, 1: int} */
    public static function findDeco(\stdClass $street, mixed $id): array
    {
        foreach (is_array($street->layers ?? null) ? $street->layers : [] as $layer) {
            foreach (is_array($layer->decos ?? null) ? $layer->decos : [] as $i => $d) {
                if (self::isObj($d) && ($d->id ?? null) === $id) {
                    return [$layer, $i];
                }
            }
        }
        return [null, -1];
    }

    public static function findLayer(\stdClass $street, mixed $id): \stdClass
    {
        foreach (is_array($street->layers ?? null) ? $street->layers : [] as $layer) {
            if (self::isObj($layer) && ($layer->id ?? null) === $id) {
                return $layer;
            }
        }
        throw new BuilderOpError('builder_no_layer', 'No layer "' . (is_string($id) ? $id : '') . '" on this street.');
    }

    /** The next free id of a geometry kind on a street (plat_<n>, wall_<n>, ladder_<n>, signpost_<n>). */
    public static function nextGeometryId(\stdClass $street, string $kind): string
    {
        self::need(array_key_exists($kind, self::GEOMETRY), 'builder_bad_op', "No geometry kind \"{$kind}\".");
        $g = self::GEOMETRY[$kind];
        $n = 0;
        $list = $street->{$g['list']} ?? null;
        foreach (is_array($list) ? $list : [] as $item) {
            $id = self::isObj($item) ? ($item->id ?? null) : null;
            if (is_string($id) && str_starts_with($id, $g['prefix']) && preg_match('/^(\d+)$/D', substr($id, strlen($g['prefix'])), $m) === 1) {
                $n = max($n, (int) $m[1]);
            }
        }
        return $g['prefix'] . ($n + 1);
    }

    /**
     * [list, index] of a geometry item by id (list is null when the street has none). The list is
     * a copy (a PHP array): a caller that changes it writes it back.
     *
     * @return array{0: ?list<mixed>, 1: int}
     */
    public static function findGeometry(\stdClass $street, string $kind, mixed $id): array
    {
        $list = $street->{self::GEOMETRY[$kind]['list']} ?? null;
        if (!is_array($list)) {
            return [null, -1];
        }
        foreach ($list as $i => $g) {
            if (self::isObj($g) && ($g->id ?? null) === $id) {
                return [$list, $i];
            }
        }
        return [$list, -1];
    }

    /** Put an object's keys in $order (the keys it names first, the rest after, as they are), in place. */
    private static function reorder(\stdClass $target, mixed $order): void
    {
        if (!is_array($order)) {
            return;
        }
        $vars = get_object_vars($target);
        $keys = [];
        foreach ($order as $k) {
            if (is_string($k) && array_key_exists($k, $vars) && !in_array($k, $keys, true)) {
                $keys[] = $k;
            }
        }
        foreach (array_keys($vars) as $k) {
            if (!in_array((string) $k, $keys, true)) {
                $keys[] = (string) $k;
            }
        }
        foreach (array_keys($vars) as $k) {
            unset($target->{$k});
        }
        foreach ($keys as $k) {
            $target->{$k} = $vars[$k];
        }
    }

    /** @return array{set: \stdClass, unset: list<string>, order?: list<string>} */
    private static function patch(\stdClass $target, mixed $set, mixed $unset, array $allowed, string $what, mixed $order = null, array $nullable = []): array
    {
        $set ??= new \stdClass();
        $unset ??= [];
        self::need(self::isObj($set), 'builder_bad_op', "{$what}: set must be an object.");
        self::need(is_array($unset) && array_is_list($unset), 'builder_bad_op', "{$what}: unset must be a list.");
        $keys = array_map('strval', array_keys(get_object_vars($set)));
        foreach ($keys as $k) {
            self::need(in_array($k, $allowed, true), 'builder_bad_op', "{$what}: \"{$k}\" cannot be set here.");
            self::need($set->{$k} !== null || in_array($k, $nullable, true), 'builder_bad_op', "{$what}: \"{$k}\" needs a value (unset removes a field).");
        }
        foreach ($unset as $k) {
            self::need(is_string($k) && in_array($k, $allowed, true), 'builder_bad_op', "{$what}: \"" . (is_string($k) ? $k : '') . '" cannot be unset here.');
        }
        $inv = ['set' => new \stdClass(), 'unset' => []];
        foreach ($keys as $k) {
            if (self::has($target, $k)) {
                $inv['set']->{$k} = self::copy($target->{$k});
            } else {
                $inv['unset'][] = $k;
            }
        }
        $before = array_map('strval', array_keys(get_object_vars($target)));
        $removed = false;
        foreach ($unset as $k) {
            if (self::has($target, $k) && !in_array($k, $keys, true)) {
                $inv['set']->{$k} = self::copy($target->{$k});
                $removed = true;
            }
        }
        foreach ($keys as $k) {
            $target->{$k} = self::copy($set->{$k});
        }
        foreach ($unset as $k) {
            if (!in_array($k, $keys, true)) {
                unset($target->{$k});
            }
        }
        self::reorder($target, $order);
        if ($removed) {
            $inv['order'] = $before;
        }
        return $inv;
    }

    private static function at(mixed $at, int $length): int
    {
        return is_int($at) ? max(0, min($at, $length)) : $length;
    }

    /** @param array<string, mixed> $fields */
    private static function op(array $fields): \stdClass
    {
        return (object) $fields;
    }

    /**
     * Apply one op to the zone (mutating it).
     *
     * @return array{inverse: \stdClass, changed: list<string>}
     */
    public static function apply(\stdClass $zone, mixed $op): array
    {
        self::need(self::isObj($op) && is_string($op->op ?? null), 'builder_bad_op', 'An op is an object with an "op" name.');
        $street = self::prop($op, 'street');
        switch ($op->op) {
            case 'zone.set':
                $inv = self::patch($zone, self::prop($op, 'set'), self::prop($op, 'unset'), self::ZONE_FIELDS, 'zone.set', self::prop($op, 'order'));
                return ['inverse' => self::op(['op' => 'zone.set'] + $inv), 'changed' => ['zone']];
            case 'hub.set':
                $hub = self::prop($op, 'hub');
                self::need($hub === null || self::isObj($hub), 'builder_bad_op', 'hub.set needs a hub or null.');
                $before = self::has($zone, 'hub') ? self::copy($zone->hub) : null;
                $keys = array_map('strval', array_keys(get_object_vars($zone)));
                if ($hub === null) {
                    unset($zone->hub);
                } else {
                    $zone->hub = self::copy($hub);
                }
                self::reorder($zone, self::prop($op, 'order'));
                $inverse = ['op' => 'hub.set', 'hub' => $before];
                if ($hub === null && $before !== null) {
                    $inverse['order'] = $keys;
                }
                return ['inverse' => self::op($inverse), 'changed' => ['hub']];
            case 'asset.set':
                $name = self::prop($op, 'name');
                $asset = self::prop($op, 'asset');
                self::need(is_string($name) && $name !== '', 'builder_bad_op', 'asset.set needs a name.');
                self::need($asset === null || self::isObj($asset), 'builder_bad_op', 'asset.set needs an asset or null.');
                if (!self::isObj($zone->assets ?? null)) {
                    $zone->assets = new \stdClass();
                }
                $before = self::has($zone->assets, $name) ? self::copy($zone->assets->{$name}) : null;
                $keys = array_map('strval', array_keys(get_object_vars($zone->assets)));
                if ($asset === null) {
                    unset($zone->assets->{$name});
                } else {
                    $zone->assets->{$name} = self::copy($asset);
                }
                self::reorder($zone->assets, self::prop($op, 'order'));
                $inverse = ['op' => 'asset.set', 'name' => $name, 'asset' => $before];
                if ($asset === null && $before !== null) {
                    $inverse['order'] = $keys;
                }
                return ['inverse' => self::op($inverse), 'changed' => ["asset:{$name}"]];
            case 'street.add':
                $row = self::prop($op, 'draftStreet');
                self::need(self::isObj($row) && ($row->key ?? null) === $street && is_string($street) && self::isObj($row->street ?? null), 'builder_bad_op', "street.add needs a draftStreet whose key is the op's street.");
                $streets = is_array($zone->streets ?? null) ? $zone->streets : [];
                foreach ($streets as $s) {
                    self::need(($s->key ?? null) !== $street, 'builder_exists', "This draft already has a street \"{$street}\".");
                    self::need(($s->street->tsid ?? null) !== ($row->street->tsid ?? null), 'builder_exists', 'This draft already has a street ' . (string) ($row->street->tsid ?? '') . '.');
                }
                $at = self::at(self::prop($op, 'at'), count($streets));
                array_splice($streets, $at, 0, [self::copy($row)]);
                $zone->streets = $streets;
                return ['inverse' => self::op(['op' => 'street.remove', 'street' => $street]), 'changed' => [$street]];
            case 'street.remove':
                $row = self::streetRow($zone, $street);
                $at = array_search($row, $zone->streets, true);
                array_splice($zone->streets, (int) $at, 1);
                return ['inverse' => self::op(['op' => 'street.add', 'street' => $street, 'draftStreet' => $row, 'at' => (int) $at]), 'changed' => [$street]];
            case 'street.set':
                $s = self::streetRow($zone, $street)->street;
                $inv = self::patch($s, self::prop($op, 'set'), self::prop($op, 'unset'), self::STREET_FIELDS, 'street.set', self::prop($op, 'order'));
                return ['inverse' => self::op(['op' => 'street.set', 'street' => $street] + $inv), 'changed' => [$street]];
            case 'editor.set':
                $row = self::streetRow($zone, $street);
                $editor = self::prop($op, 'editor');
                self::need($editor === null || self::isObj($editor), 'builder_bad_op', 'editor.set needs editor state or null.');
                $before = self::has($row, 'editor') ? self::copy($row->editor) : null;
                $keys = array_map('strval', array_keys(get_object_vars($row)));
                if ($editor === null) {
                    unset($row->editor);
                } else {
                    $row->editor = self::copy($editor);
                }
                self::reorder($row, self::prop($op, 'order'));
                $inverse = ['op' => 'editor.set', 'street' => $street, 'editor' => $before];
                if ($editor === null && $before !== null) {
                    $inverse['order'] = $keys;
                }
                return ['inverse' => self::op($inverse), 'changed' => ["{$street}/editor"]];
            case 'layer.set':
                $layerId = self::prop($op, 'layer');
                $layer = self::findLayer(self::streetRow($zone, $street)->street, $layerId);
                $inv = self::patch($layer, self::prop($op, 'set'), self::prop($op, 'unset'), self::LAYER_FIELDS, 'layer.set', self::prop($op, 'order'));
                return ['inverse' => self::op(['op' => 'layer.set', 'street' => $street, 'layer' => $layerId] + $inv), 'changed' => ["{$street}/{$layerId}"]];
            case 'deco.add':
                $s = self::streetRow($zone, $street)->street;
                $layer = self::findLayer($s, self::prop($op, 'layer'));
                $deco = self::prop($op, 'deco');
                self::need(self::isObj($deco) && is_string($deco->id ?? null) && $deco->id !== '' && is_string($deco->sprite ?? null), 'builder_bad_op', 'deco.add needs a deco with an id and a sprite.');
                self::need(self::findDeco($s, $deco->id)[0] === null, 'builder_exists', "A deco {$deco->id} is already on this street.");
                foreach (array_keys(get_object_vars($deco)) as $k) {
                    self::need($k === 'id' || in_array((string) $k, self::DECO_FIELDS, true), 'builder_bad_op', "deco.add: \"{$k}\" is not a deco field.");
                }
                $decos = is_array($layer->decos ?? null) ? $layer->decos : [];
                array_splice($decos, self::at(self::prop($op, 'at'), count($decos)), 0, [self::copy($deco)]);
                $layer->decos = $decos;
                return ['inverse' => self::op(['op' => 'deco.remove', 'street' => $street, 'id' => $deco->id]), 'changed' => ["{$street}/{$deco->id}"]];
            case 'deco.remove':
                $id = self::prop($op, 'id');
                [$layer, $i] = self::findDeco(self::streetRow($zone, $street)->street, $id);
                self::need($layer !== null, 'builder_no_deco', 'No deco ' . (is_string($id) ? $id : '') . ' on this street.');
                [$deco] = array_splice($layer->decos, $i, 1);
                return ['inverse' => self::op(['op' => 'deco.add', 'street' => $street, 'layer' => $layer->id, 'deco' => $deco, 'at' => $i]), 'changed' => ["{$street}/{$id}"]];
            case 'deco.set':
                $id = self::prop($op, 'id');
                [$layer, $i] = self::findDeco(self::streetRow($zone, $street)->street, $id);
                self::need($layer !== null, 'builder_no_deco', 'No deco ' . (is_string($id) ? $id : '') . ' on this street.');
                $inv = self::patch($layer->decos[$i], self::prop($op, 'set'), self::prop($op, 'unset'), self::DECO_FIELDS, 'deco.set', self::prop($op, 'order'));
                return ['inverse' => self::op(['op' => 'deco.set', 'street' => $street, 'id' => $id] + $inv), 'changed' => ["{$street}/{$id}"]];
            case 'deco.move':
                $id = self::prop($op, 'id');
                $s = self::streetRow($zone, $street)->street;
                [$from, $i] = self::findDeco($s, $id);
                self::need($from !== null, 'builder_no_deco', 'No deco ' . (is_string($id) ? $id : '') . ' on this street.');
                $to = self::findLayer($s, self::prop($op, 'layer'));
                [$deco] = array_splice($from->decos, $i, 1);
                $inv = self::patch($deco, self::prop($op, 'set') ?? new \stdClass(), [], ['x', 'y'], 'deco.move');
                $decos = is_array($to->decos ?? null) ? $to->decos : [];
                array_splice($decos, self::at(self::prop($op, 'at'), count($decos)), 0, [$deco]);
                $to->decos = $decos;
                $back = ['op' => 'deco.move', 'street' => $street, 'id' => $id, 'layer' => $from->id, 'at' => $i];
                if (get_object_vars($inv['set']) !== []) {
                    $back['set'] = $inv['set'];
                }
                return ['inverse' => self::op($back), 'changed' => ["{$street}/{$id}"]];
            case 'connect.add':
                $s = self::streetRow($zone, $street)->street;
                $postId = self::prop($op, 'signpost');
                [$list, $i] = self::findGeometry($s, 'signpost', $postId);
                self::need($i >= 0, 'builder_no_signpost', 'No signpost ' . (is_string($postId) ? $postId : '') . ' on this street.');
                $post = $list[$i];
                $c = self::prop($op, 'connect');
                self::need(self::isObj($c) && is_string($c->tsid ?? null) && is_string($c->label ?? null), 'builder_bad_op', 'connect.add needs a connect with a tsid and a label.');
                foreach (array_keys(get_object_vars($c)) as $k) {
                    self::need(in_array((string) $k, self::CONNECT_FIELDS, true), 'builder_bad_op', "connect.add: \"{$k}\" is not a connect field.");
                }
                $connects = is_array($post->connects ?? null) ? $post->connects : [];
                $at = self::at(self::prop($op, 'at'), count($connects));
                array_splice($connects, $at, 0, [self::copy($c)]);
                $post->connects = $connects;
                return ['inverse' => self::op(['op' => 'connect.remove', 'street' => $street, 'signpost' => $postId, 'at' => $at]), 'changed' => ["{$street}/{$postId}"]];
            case 'connect.remove':
                $s = self::streetRow($zone, $street)->street;
                $postId = self::prop($op, 'signpost');
                [$list, $i] = self::findGeometry($s, 'signpost', $postId);
                self::need($i >= 0, 'builder_no_signpost', 'No signpost ' . (is_string($postId) ? $postId : '') . ' on this street.');
                $post = $list[$i];
                $at = self::prop($op, 'at');
                self::need(is_int($at) && $at >= 0 && $at < (is_array($post->connects ?? null) ? count($post->connects) : 0), 'builder_no_connect', 'Signpost ' . (is_string($postId) ? $postId : '') . ' has no arm ' . (is_int($at) ? $at : '') . '.');
                [$c] = array_splice($post->connects, $at, 1);
                return ['inverse' => self::op(['op' => 'connect.add', 'street' => $street, 'signpost' => $postId, 'connect' => $c, 'at' => $at]), 'changed' => ["{$street}/{$postId}"]];
            case 'connect.set':
                $s = self::streetRow($zone, $street)->street;
                $postId = self::prop($op, 'signpost');
                [$list, $i] = self::findGeometry($s, 'signpost', $postId);
                self::need($i >= 0, 'builder_no_signpost', 'No signpost ' . (is_string($postId) ? $postId : '') . ' on this street.');
                $post = $list[$i];
                $at = self::prop($op, 'at');
                self::need(is_int($at) && $at >= 0 && $at < (is_array($post->connects ?? null) ? count($post->connects) : 0), 'builder_no_connect', 'Signpost ' . (is_string($postId) ? $postId : '') . ' has no arm ' . (is_int($at) ? $at : '') . '.');
                $inv = self::patch($post->connects[$at], self::prop($op, 'set'), self::prop($op, 'unset'), self::CONNECT_FIELDS, 'connect.set', self::prop($op, 'order'));
                return ['inverse' => self::op(['op' => 'connect.set', 'street' => $street, 'signpost' => $postId, 'at' => $at] + $inv), 'changed' => ["{$street}/{$postId}"]];
            case 'population.set':
                $row = self::streetRow($zone, $street);
                $pop = self::prop($op, 'population');
                self::need($pop === null || (self::isObj($pop) && self::isObj($pop->counts ?? null)), 'builder_bad_op', 'population.set needs a population with counts, or null.');
                $before = self::has($row, 'population') ? self::copy($row->population) : null;
                $keys = array_map('strval', array_keys(get_object_vars($row)));
                if ($pop === null) {
                    unset($row->population);
                } else {
                    $row->population = self::copy($pop);
                }
                self::reorder($row, self::prop($op, 'order'));
                $inverse = ['op' => 'population.set', 'street' => $street, 'population' => $before];
                if ($pop === null && $before !== null) {
                    $inverse['order'] = $keys;
                }
                return ['inverse' => self::op($inverse), 'changed' => ["{$street}/population"]];
            case 'hubmap.set':
                self::need(self::isObj($zone->hub ?? null), 'builder_no_hub', 'This draft has no hub yet.');
                $map = self::prop($op, 'map');
                self::need($map === null || (self::isObj($map) && is_array($map->streets ?? null)), 'builder_bad_op', 'hubmap.set needs a map with a streets list, or null.');
                $before = self::has($zone->hub, 'map') ? self::copy($zone->hub->map) : null;
                $keys = array_map('strval', array_keys(get_object_vars($zone->hub)));
                if ($map === null) {
                    unset($zone->hub->map);
                } else {
                    $zone->hub->map = self::copy($map);
                }
                self::reorder($zone->hub, self::prop($op, 'order'));
                $inverse = ['op' => 'hubmap.set', 'map' => $before];
                if ($map === null && $before !== null) {
                    $inverse['order'] = $keys;
                }
                return ['inverse' => self::op($inverse), 'changed' => ['hub']];
            case 'edit.add':
                $e = self::prop($op, 'edit');
                self::need(self::isObj($e) && is_string($e->tsid ?? null) && in_array($e->op ?? null, self::EDIT_KINDS, true), 'builder_bad_op', 'edit.add needs an edit with a tsid and an op (add-connect or add-signpost).');
                foreach (array_keys(get_object_vars($e)) as $k) {
                    self::need(in_array((string) $k, self::EDIT_FIELDS, true), 'builder_bad_op', "edit.add: \"{$k}\" is not an edit field.");
                }
                $c = $e->op === 'add-connect' ? ($e->connect ?? null) : null;
                if ($e->op === 'add-connect') {
                    self::need(is_string($e->signpost ?? null) && $e->signpost !== '' && self::isObj($c) && is_string($c->tsid ?? null) && is_string($c->label ?? null), 'builder_bad_op', 'An add-connect edit names a signpost and a connect with a tsid and a label.');
                } else {
                    self::need(self::isObj($e->newSignpost ?? null), 'builder_bad_op', 'An add-signpost edit carries the new signpost.');
                }
                if ($c !== null) {
                    foreach (array_keys(get_object_vars($c)) as $k) {
                        self::need(in_array((string) $k, self::CONNECT_FIELDS, true), 'builder_bad_op', "edit.add: \"{$k}\" is not a connect field.");
                    }
                }
                $fresh = !is_array($zone->edits ?? null);
                $edits = $fresh ? [] : $zone->edits;
                $at = self::at(self::prop($op, 'at'), count($edits));
                array_splice($edits, $at, 0, [self::copy($e)]);
                $zone->edits = $edits;
                self::reorder($zone, self::prop($op, 'order'));
                $inverse = ['op' => 'edit.remove', 'at' => $at];
                if ($fresh) {
                    $inverse['prune'] = true;
                }
                return ['inverse' => self::op($inverse), 'changed' => ['edits']];
            case 'edit.remove':
                $edits = is_array($zone->edits ?? null) ? $zone->edits : [];
                $at = self::prop($op, 'at');
                self::need(is_int($at) && $at >= 0 && $at < count($edits), 'builder_no_edit', 'This draft has no edit ' . (is_int($at) ? $at : (is_string($at) ? $at : '')) . '.');
                $keys = array_map('strval', array_keys(get_object_vars($zone)));
                [$e] = array_splice($edits, $at, 1);
                $inverse = ['op' => 'edit.add', 'edit' => $e, 'at' => $at];
                if (self::prop($op, 'prune') === true && $edits === []) {
                    unset($zone->edits);
                    $inverse['order'] = $keys;
                } else {
                    $zone->edits = $edits;
                }
                return ['inverse' => self::op($inverse), 'changed' => ['edits']];
            default:
                $parts = explode('.', $op->op);
                $kind = $parts[0];
                $verb = $parts[1] ?? null;
                if (array_key_exists($kind, self::GEOMETRY) && in_array($verb, ['add', 'remove', 'set'], true)) {
                    return self::applyGeometry($zone, $op, $kind, $verb);
                }
                throw new BuilderOpError('builder_bad_op', "Unknown op \"{$op->op}\".");
        }
    }

    /**
     * platform.*, barrier.*, ladder.* and signpost.* (ops.js applyGeometryOp).
     *
     * @return array{inverse: \stdClass, changed: list<string>}
     */
    private static function applyGeometry(\stdClass $zone, \stdClass $op, string $kind, string $verb): array
    {
        $g = self::GEOMETRY[$kind];
        $name = $op->op;
        $key = self::prop($op, 'street');
        $street = self::streetRow($zone, $key)->street;
        if ($verb === 'add') {
            $item = self::prop($op, $kind);
            self::need(self::isObj($item), 'builder_bad_op', "{$name} needs a {$kind}.");
            $allowed = $kind === 'signpost' ? [...$g['fields'], 'connects'] : $g['fields'];
            foreach (array_keys(get_object_vars($item)) as $k) {
                self::need($k === 'id' || in_array((string) $k, $allowed, true), 'builder_bad_op', "{$name}: \"{$k}\" is not a {$kind} field.");
            }
            $hasId = self::has($item, 'id');
            self::need(!$hasId || (is_string($item->id) && $item->id !== ''), 'builder_bad_op', "{$name}: an id is a string.");
            if ($kind === 'signpost' && self::has($item, 'connects')) {
                self::need(is_array($item->connects), 'builder_bad_op', 'signpost.add: connects is a list.');
                foreach ($item->connects as $c) {
                    self::need(self::isObj($c) && is_string($c->tsid ?? null) && is_string($c->label ?? null), 'builder_bad_op', 'signpost.add: each connect has a tsid and a label.');
                    foreach (array_keys(get_object_vars($c)) as $k) {
                        self::need(in_array((string) $k, self::CONNECT_FIELDS, true), 'builder_bad_op', "signpost.add: \"{$k}\" is not a connect field.");
                    }
                }
            }
            $id = $hasId ? $item->id : self::nextGeometryId($street, $kind);
            self::need(self::findGeometry($street, $kind, $id)[1] < 0, 'builder_exists', "A {$kind} {$id} is already on this street.");
            if ($hasId) {
                $copy = self::copy($item);
            } else {
                // { id, ...item }: the id goes first.
                $copy = new \stdClass();
                $copy->id = $id;
                foreach (get_object_vars(self::copy($item)) as $k => $v) {
                    $copy->{$k} = $v;
                }
            }
            $fresh = !is_array($street->{$g['list']} ?? null);
            $list = $fresh ? [] : $street->{$g['list']};
            array_splice($list, self::at(self::prop($op, 'at'), count($list)), 0, [$copy]);
            $street->{$g['list']} = $list;
            self::reorder($street, self::prop($op, 'order'));
            $inverse = ['op' => "{$kind}.remove", 'street' => $key, 'id' => $id];
            if ($fresh) {
                $inverse['prune'] = true;
            }
            return ['inverse' => self::op($inverse), 'changed' => ["{$key}/{$id}"]];
        }
        $id = self::prop($op, 'id');
        [$list, $i] = self::findGeometry($street, $kind, $id);
        self::need($i >= 0, "builder_no_{$kind}", "No {$kind} " . (is_string($id) ? $id : '') . ' on this street.');
        if ($verb === 'remove') {
            $keys = array_map('strval', array_keys(get_object_vars($street)));
            [$item] = array_splice($list, $i, 1);
            $inverse = ['op' => "{$kind}.add", 'street' => $key, $kind => $item, 'at' => $i];
            if (self::prop($op, 'prune') === true && $list === []) {
                unset($street->{$g['list']});
                $inverse['order'] = $keys;
            } else {
                $street->{$g['list']} = $list;
            }
            return ['inverse' => self::op($inverse), 'changed' => ["{$key}/{$id}"]];
        }
        $inv = self::patch($list[$i], self::prop($op, 'set'), self::prop($op, 'unset'), $g['fields'], $name, self::prop($op, 'order'), self::NULLABLE);
        return ['inverse' => self::op(['op' => $name, 'street' => $key, 'id' => $id] + $inv), 'changed' => ["{$key}/{$id}"]];
    }

    /**
     * A batch, all or nothing: a failing op leaves the zone as it was and the error carries its index.
     *
     * @return array{inverses: list<\stdClass>, changed: list<string>}
     */
    public static function applyAll(\stdClass $zone, mixed $ops): array
    {
        self::need(is_array($ops) && array_is_list($ops), 'builder_bad_op', 'ops must be a list.');
        $done = [];
        $changed = [];
        foreach ($ops as $i => $op) {
            try {
                $r = self::apply($zone, $op);
            } catch (BuilderOpError $e) {
                for ($j = count($done) - 1; $j >= 0; $j--) {
                    self::apply($zone, $done[$j]);
                }
                throw $e->at($i);
            }
            $done[] = $r['inverse'];
            foreach ($r['changed'] as $c) {
                if (!in_array($c, $changed, true)) {
                    $changed[] = $c;
                }
            }
        }
        return ['inverses' => array_reverse($done), 'changed' => $changed];
    }

    /** "3 deco.set, 1 deco.add". @param list<mixed> $ops */
    public static function summary(array $ops): string
    {
        $n = [];
        foreach ($ops as $op) {
            $k = is_object($op) && is_string($op->op ?? null) ? $op->op : '?';
            $n[$k] = ($n[$k] ?? 0) + 1;
        }
        $out = [];
        foreach ($n as $k => $v) {
            $out[] = "{$v} {$k}";
        }
        return implode(', ', $out);
    }

    /**
     * The store's structural check before anything is kept: the shape the editor and the game
     * rely on, and the limits of spec section 12. A list of {rule, path, message}; empty is good.
     *
     * @return list<array{rule: string, path: string, message: string}>
     */
    public static function check(mixed $zone, string $id): array
    {
        $bad = [];
        $say = static function (string $path, string $message) use (&$bad): void {
            if (count($bad) < 20) {
                $bad[] = ['rule' => 'SCH-001', 'path' => $path, 'message' => $message];
            }
        };
        if (!self::isObj($zone)) {
            $say('', 'A zone is a JSON object.');
            return $bad;
        }
        if (($zone->format ?? null) !== 'groddle-zone/1') {
            $say('/format', 'The format is groddle-zone/1.');
        }
        if (($zone->id ?? null) !== $id) {
            $say('/id', "The zone's id is the draft's, {$id}.");
        }
        $title = $zone->title ?? null;
        if (!is_string($title) || $title === '' || mb_strlen($title) > 80) {
            $say('/title', 'A title of 1 to 80 characters.');
        }
        if (self::has($zone, 'hub') && (!self::isObj($zone->hub) || !is_string($zone->hub->id ?? null) || preg_match('/^[0-9]{1,4}$/', $zone->hub->id) !== 1 || !is_string($zone->hub->name ?? null))) {
            $say('/hub', 'A hub has an id (digits) and a name.');
        }
        if (!self::isObj($zone->assets ?? null)) {
            $say('/assets', 'assets is an object.');
        }
        $streets = $zone->streets ?? null;
        if (!is_array($streets) || !array_is_list($streets)) {
            $say('/streets', 'streets is a list.');
            return $bad;
        }
        if (count($streets) > self::MAX_STREETS) {
            $say('/streets', 'At most ' . self::MAX_STREETS . ' streets in a zone.');
        }
        $keys = $tsids = [];
        foreach ($streets as $n => $row) {
            $p = "/streets/{$n}";
            if (!self::isObj($row) || !is_string($row->key ?? null) || preg_match('/^[a-z][a-z0-9-]{0,31}$/', $row->key) !== 1) {
                $say("{$p}/key", 'A street key is lowercase letters, digits and hyphens.');
                continue;
            }
            if (isset($keys[$row->key])) {
                $say("{$p}/key", "Two streets share the key {$row->key}.");
            }
            $keys[$row->key] = true;
            $s = $row->street ?? null;
            if (!self::isObj($s)) {
                $say("{$p}/street", 'A street is an object.');
                continue;
            }
            $tsid = $s->tsid ?? null;
            if (!is_string($tsid) || preg_match('/^GZ[0-9A-Z]{13}$/', $tsid) !== 1) {
                $say("{$p}/street/tsid", 'A builder street tsid is GZ and 13 capitals or digits.');
            } elseif (isset($tsids[$tsid])) {
                $say("{$p}/street/tsid", "Two streets share the tsid {$tsid}.");
            } else {
                $tsids[$tsid] = true;
            }
            foreach (['events', 'eventBoxes', 'physics'] as $f) {
                if (self::has($s, $f)) {
                    $say("{$p}/street/{$f}", "Builder streets carry no {$f}.");
                }
            }
            $label = $s->label ?? null;
            if (!is_string($label) || $label === '' || mb_strlen($label) > 48) {
                $say("{$p}/street/label", 'A label of 1 to 48 characters.');
            }
            $b = $s->bounds ?? null;
            if (!self::isObj($b) || !is_numeric($b->l ?? null) || !is_numeric($b->r ?? null) || !is_numeric($b->t ?? null) || !is_numeric($b->b ?? null)) {
                $say("{$p}/street/bounds", 'bounds has l, r, t and b.');
            } elseif ($b->r - $b->l > 8000 || $b->b - $b->t > 6000 || $b->r <= $b->l || $b->b <= $b->t) {
                $say("{$p}/street/bounds", 'A street is at most 8,000 by 6,000.');
            }
            $plats = $s->platforms ?? null;
            if (!is_array($plats) || $plats === []) {
                $say("{$p}/street/platforms", 'A street has at least one platform.');
            } elseif (count($plats) > self::MAX_PLATFORMS) {
                $say("{$p}/street/platforms", 'At most ' . self::MAX_PLATFORMS . ' platforms.');
            }
            $layers = $s->layers ?? null;
            if (!is_array($layers) || !array_is_list($layers) || $layers === []) {
                $say("{$p}/street/layers", 'A street has layers.');
                continue;
            }
            if (count($layers) > self::MAX_LAYERS) {
                $say("{$p}/street/layers", 'At most ' . self::MAX_LAYERS . ' layers.');
            }
            $middle = 0;
            $decos = 0;
            $layerIds = $decoIds = [];
            foreach ($layers as $i => $layer) {
                $lp = "{$p}/street/layers/{$i}";
                if (!self::isObj($layer) || !is_string($layer->id ?? null) || $layer->id === '' || !is_array($layer->decos ?? null) || !is_numeric($layer->z ?? null)) {
                    $say($lp, 'A layer has an id, a z and a list of decos.');
                    continue;
                }
                if (isset($layerIds[$layer->id])) {
                    $say("{$lp}/id", "Two layers share the id {$layer->id}.");
                }
                $layerIds[$layer->id] = true;
                $middle += $layer->id === 'middleground' ? 1 : 0;
                foreach ($layer->decos as $j => $d) {
                    $decos++;
                    if (!self::isObj($d) || !is_string($d->sprite ?? null) || !is_numeric($d->x ?? null) || !is_numeric($d->y ?? null)) {
                        $say("{$lp}/decos/{$j}", 'A deco has a sprite, x and y.');
                        continue;
                    }
                    if (is_string($d->id ?? null)) {
                        if (isset($decoIds[$d->id])) {
                            $say("{$lp}/decos/{$j}/id", "Two decos share the id {$d->id}.");
                        }
                        $decoIds[$d->id] = true;
                    }
                }
            }
            if ($middle !== 1) {
                $say("{$p}/street/layers", 'A street has exactly one middleground layer.');
            }
            if ($decos > self::MAX_DECOS) {
                $say("{$p}/street/layers", 'At most ' . number_format(self::MAX_DECOS) . ' decos on a street.');
            }
        }
        return $bad;
    }
}
