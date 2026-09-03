<?php

function admin_find_index(array $promos, string $id): ?int {
    foreach ($promos as $i => $p) {
        if (($p['id'] ?? '') === $id) {
            return $i;
        }
    }
    return null;
}

function admin_toggle(array $promos, string $id): array {
    $i = admin_find_index($promos, $id);
    if ($i !== null) {
        $promos[$i]['enabled'] = !($promos[$i]['enabled'] ?? false);
    }
    return $promos;
}

function admin_move(array $promos, string $id, int $delta): array {
    $i = admin_find_index($promos, $id);
    if ($i === null) {
        return $promos;
    }
    $j = $i + $delta;
    if ($j < 0 || $j >= count($promos)) {
        return $promos;
    }
    [$promos[$i], $promos[$j]] = [$promos[$j], $promos[$i]];
    return $promos;
}

function admin_delete(array $promos, string $id): array {
    $i = admin_find_index($promos, $id);
    if ($i === null) {
        return $promos;
    }
    unset($promos[$i]);
    return array_values($promos);
}

function admin_promo_from_post(array $post): array {
    $out = promos_defaults();
    foreach (['badge', 'title', 'note', 'modal_title', 'modal_sub', 'modal_text'] as $f) {
        $out[$f] = trim((string)($post[$f] ?? ''));
    }
    $out['theme']   = (string)($post['theme'] ?? 't-blue');
    $out['big']     = !empty($post['big']);
    $out['enabled'] = !empty($post['enabled']);
    return $out;
}
