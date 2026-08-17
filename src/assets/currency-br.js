/**
 * currency-br.js — campos de moeda no padrão brasileiro (R$ 1.234,56).
 *
 * Marque qualquer input de valor monetário com `data-currency`:
 *
 *     <input type="number" step="0.01" name="total_value" id="total_value" data-currency>
 *
 * Em tempo de execução o input original é apenas ocultado (mantendo tipo, id,
 * name, classes e listeners) e continua guardando SEMPRE o número cru com ponto
 * decimal ("1234.56"). Ao lado dele é criado um campo de texto visível com a
 * máscara brasileira. Com isso:
 *
 *   - `parseFloat(campo.value)` e o envio do formulário continuam recebendo número
 *     cru — nenhum handler PHP/JS existente precisa ser alterado;
 *   - `campo.value = 1234.56` (escrita programática) reflete na máscara;
 *   - a digitação do usuário dispara `input`/`change` no campo original, então
 *     handlers como `oninput="calculateSimulation()"` seguem funcionando.
 *
 * A máscara é do tipo "centavos": os dígitos entram pela direita, ou seja
 * digitar 1 2 3 4 5 6 resulta em 1.234,56. Isso elimina a ambiguidade entre
 * ponto de milhar e vírgula decimal.
 */
(function () {
    'use strict';

    var SELECTOR = 'input[data-currency]';
    var MAX_DIGITS = 15; // ~9,9 trilhões: acima disso perde-se precisão no double
    var nativeValue = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');
    var formatter = new Intl.NumberFormat('pt-BR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
    var renderers = []; // atualizadores de exibição, usados no reset de formulário

    /** Número -> "1.234,56". */
    function format(value) {
        var n = typeof value === 'number' ? value : parse(value);
        if (n === null) {
            return '';
        }
        return formatter.format(n);
    }

    /** "R$ 1.234,56" | "1234.56" | 1234.56 -> 1234.56 (null quando vazio/inválido). */
    function parse(value) {
        if (value === null || value === undefined || value === '') {
            return null;
        }
        if (typeof value === 'number') {
            return isFinite(value) ? value : null;
        }
        var text = String(value).trim();
        if (text === '') {
            return null;
        }
        // Formato brasileiro ("1.234,56"): remove os pontos de milhar e troca a vírgula.
        if (text.indexOf(',') !== -1) {
            text = text.replace(/\./g, '').replace(',', '.');
        }
        text = text.replace(/[^\d.\-]/g, '');
        var n = parseFloat(text);
        return isFinite(n) ? n : null;
    }

    function readRaw(el) {
        return nativeValue.get.call(el);
    }

    function writeRaw(el, text) {
        nativeValue.set.call(el, text);
    }

    /**
     * Intercepta a escrita programática de `.value` no campo original para que a
     * máscara acompanhe. A leitura continua sendo a nativa (número cru).
     */
    function interceptValue(el, onWrite) {
        Object.defineProperty(el, 'value', {
            configurable: true,
            enumerable: true,
            get: function () {
                return nativeValue.get.call(this);
            },
            set: function (v) {
                nativeValue.set.call(this, v);
                onWrite();
            }
        });
    }

    function attach(source) {
        if (source.dataset.currencyReady === '1') {
            return;
        }
        source.dataset.currencyReady = '1';

        var field = document.createElement('span');
        field.className = 'currency-field';

        var prefix = document.createElement('span');
        prefix.className = 'currency-prefix';
        prefix.textContent = 'R$';

        var view = document.createElement('input');
        view.type = 'text';
        view.className = 'currency-input';
        view.setAttribute('inputmode', 'decimal');
        view.autocomplete = 'off';
        if (source.id) {
            view.id = source.id + '__brl';
        }
        // Vários campos são estilizados por atributo `style` inline; herdar isso mantém
        // a aparência idêntica à do campo original (o prefixo exige o recuo à esquerda).
        if (source.hasAttribute('style')) {
            view.setAttribute('style', source.getAttribute('style'));
        }
        view.style.paddingLeft = '2.5rem';
        view.style.textAlign = 'right';
        if (source.placeholder) {
            view.placeholder = source.placeholder;
        }
        if (source.title) {
            view.title = source.title;
        }
        if (source.disabled) {
            view.disabled = true;
        }
        if (source.readOnly) {
            view.readOnly = true;
        }
        // O campo original é apenas ocultado — mantê-lo como `number` preserva o
        // comportamento nativo de `form.reset()` (um `type=hidden` guardaria o valor
        // no próprio atributo e nunca voltaria ao padrão). Em compensação, ele não
        // pode carregar restrições: um campo invisível reprovado na validação
        // bloquearia o envio com "invalid form control is not focusable".
        if (source.hasAttribute('required')) {
            source.removeAttribute('required');
            view.required = true;
        }
        source.removeAttribute('min');
        source.removeAttribute('max');
        source.setAttribute('step', 'any');

        source.parentNode.insertBefore(field, source);
        field.appendChild(prefix);
        field.appendChild(view);
        field.appendChild(source);
        source.classList.add('currency-source');
        source.style.display = 'none'; // garante a ocultação mesmo sem o style.css

        var syncing = false;

        function render() {
            var text = format(readRaw(source));
            if (view.value !== text) {
                view.value = text;
            }
        }

        view.addEventListener('input', function () {
            var digits = view.value.replace(/\D/g, '').replace(/^0+(?=\d)/, '').slice(0, MAX_DIGITS);
            var number = digits === '' ? null : parseInt(digits, 10) / 100;

            view.value = number === null ? '' : formatter.format(number);

            syncing = true;
            writeRaw(source, number === null ? '' : number.toFixed(2));
            syncing = false;

            source.dispatchEvent(new Event('input', { bubbles: true }));
        });

        view.addEventListener('change', function () {
            source.dispatchEvent(new Event('change', { bubbles: true }));
        });

        interceptValue(source, function () {
            if (!syncing) {
                render();
            }
        });

        // Após um form.reset() os valores voltam ao padrão sem disparar evento no campo.
        var form = source.form;
        if (form && form.dataset.currencyReset !== '1') {
            form.dataset.currencyReset = '1';
            form.addEventListener('reset', function () {
                setTimeout(refresh, 0);
            });
        }

        renderers.push(render);
        render();
    }

    /** Aplica a máscara em todos os campos ainda não tratados dentro de `root`. */
    function scan(root) {
        var scope = root || document;
        if (scope.querySelectorAll) {
            Array.prototype.forEach.call(scope.querySelectorAll(SELECTOR), attach);
        }
    }

    /** Reexibe todos os campos a partir do valor cru atual. */
    function refresh() {
        renderers.forEach(function (render) {
            render();
        });
    }

    function start() {
        scan(document);
        // Linhas de lote/liquidação são criadas dinamicamente via innerHTML.
        if (window.MutationObserver) {
            new MutationObserver(function (mutations) {
                for (var i = 0; i < mutations.length; i++) {
                    if (mutations[i].addedNodes.length) {
                        scan(document);
                        return;
                    }
                }
            }).observe(document.body, { childList: true, subtree: true });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }

    window.CurrencyBR = {
        format: format,
        parse: parse,
        scan: scan,
        refresh: refresh
    };
})();
