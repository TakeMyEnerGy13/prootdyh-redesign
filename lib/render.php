<?php

function promos_e(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

function promos_visible(array $data): array {
    return array_values(array_filter(
        $data['promos'] ?? [],
        static fn(array $p): bool => ($p['enabled'] ?? false) === true
    ));
}

function promos_render_tile(array $p): string {
    $cls = 'ptile' . (($p['big'] ?? false) ? ' is-big' : '') . ' ' . promos_e($p['theme'] ?? 't-blue');
    return '      <a class="' . $cls . '" href="#p-' . promos_e($p['id']) . '">' . "\n"
         . '        <span class="pt-badge">' . promos_e($p['badge']) . '</span>' . "\n"
         . '        <span class="pt-city">' . promos_e($p['title']) . '</span>' . "\n"
         . '        <span class="pt-note">' . promos_e($p['note']) . '</span>' . "\n"
         . '      </a>' . "\n";
}

function promos_render_modal(array $p): string {
    $x = '<a class="pmodal__x" href="#_" aria-label="Закрыть">'
       . '<svg viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></svg></a>';
    return '<div class="pmodal" id="p-' . promos_e($p['id']) . '">' . "\n"
         . '  <a class="pmodal__bg" href="#_" aria-label="Закрыть"></a>' . "\n"
         . '  <div class="pmodal__win">' . "\n"
         . '    ' . $x . "\n"
         . '    <h3>' . promos_e($p['modal_title']) . '</h3>' . "\n"
         . '    <p class="pmodal__sub">' . promos_e($p['modal_sub']) . '</p>' . "\n"
         . '    <div class="pmodal__txt">' . nl2br(promos_e($p['modal_text'])) . "\n"
         . '      <br><a class="btn" href="/#contact">Узнать условия</a></div>' . "\n"
         . '  </div>' . "\n"
         . '</div>' . "\n";
}
