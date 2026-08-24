// Helper para insertar iconos SVG del sprite (#icon-sprite en web/app.php).
// Uso: icon('home') -> <svg class="icon"><use href="#icon-home"></use></svg>
//      icon('check', 'icon-sm') -> añade clases extra al <svg>
function icon(name, extraClass) {
  var cls = extraClass ? ('icon ' + extraClass) : 'icon';
  return '<svg class="' + cls + '" aria-hidden="true" focusable="false"><use href="#icon-' + name + '"></use></svg>';
}

(function () {
  'use strict';

  var SVG_NS = 'http://www.w3.org/2000/svg';
  var LABEL_ICONS = [
    { pattern: /^(nuevo|nueva|agregar|registrar|crear|completar)\b/, icon: 'plus' },
    { pattern: /^(editar|asignar|administrar|cultivo|categoría)\b/, icon: 'edit' },
    { pattern: /^(ver|revisar)\b/, icon: 'eye' },
    { pattern: /^(eliminar)\b/, icon: 'trash' },
    { pattern: /^(clave|cambiar clave|restablecer clave)\b/, icon: 'key' },
    { pattern: /^(activar|desactivar|inactivar)\b/, icon: 'power' },
    { pattern: /^(guardar)\b/, icon: 'save' }
  ];

  function createIcon(name) {
    var svg = document.createElementNS(SVG_NS, 'svg');
    var use = document.createElementNS(SVG_NS, 'use');
    svg.setAttribute('class', 'icon');
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('focusable', 'false');
    use.setAttribute('href', '#icon-' + name);
    svg.appendChild(use);
    return svg;
  }

  function normalizedLabel(button) {
    return (button.textContent || '').replace(/^\s*\+\s*/, '').trim().toLocaleLowerCase('es');
  }

  function removeLeadingPlus(button) {
    var walker = document.createTreeWalker(button, NodeFilter.SHOW_TEXT);
    var node;
    while ((node = walker.nextNode())) {
      if (/^\s*\+\s*/.test(node.nodeValue || '')) {
        node.nodeValue = node.nodeValue.replace(/^\s*\+\s*/, '');
        return;
      }
    }
  }

  function iconFor(button) {
    var label = normalizedLabel(button);
    if (!label) return '';
    for (var i = 0; i < LABEL_ICONS.length; i += 1) {
      if (LABEL_ICONS[i].pattern.test(label)) return LABEL_ICONS[i].icon;
    }
    return '';
  }

  function enhanceButton(button) {
    if (!button || button.dataset.iconEnhanced || button.querySelector(':scope > .icon, :scope > span > .icon')) return;
    var isAction = button.classList.contains('table-action');
    var isMainAction = button.classList.contains('primary-button') || button.classList.contains('compact-action');
    if (!isAction && !isMainAction) return;
    var name = iconFor(button);
    if (!name) return;

    button.dataset.iconEnhanced = 'true';
    removeLeadingPlus(button);
    var labelContainer = button.children.length === 1 && button.firstElementChild.tagName === 'SPAN'
      ? button.firstElementChild
      : button;
    labelContainer.insertBefore(createIcon(name), labelContainer.firstChild);
    button.classList.add('icon-enhanced');
  }

  function enhanceClose(button) {
    if (!button || button.dataset.iconEnhanced || button.querySelector('.icon')) return;
    button.dataset.iconEnhanced = 'true';
    button.textContent = '';
    button.appendChild(createIcon('close'));
    if (!button.getAttribute('aria-label')) button.setAttribute('aria-label', 'Cerrar');
    if (!button.title) button.title = 'Cerrar';
  }

  function enhance(root) {
    if (!root || root.nodeType !== 1 && root.nodeType !== 9) return;
    var buttons = root.matches && root.matches('button') ? [root] : root.querySelectorAll('button');
    Array.prototype.forEach.call(buttons, function (button) {
      if (button.classList.contains('dialog-close')) enhanceClose(button);
      else enhanceButton(button);
    });
  }

  function start() {
    enhance(document);
    new MutationObserver(function (mutations) {
      mutations.forEach(function (mutation) {
        Array.prototype.forEach.call(mutation.addedNodes, function (node) {
          if (node.nodeType === 1) enhance(node);
        });
      });
    }).observe(document.body, { childList: true, subtree: true });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
}());
