// The site's stylesheet as a constructable sheet for rendered cards, fetched once per page.
// Ported from the Craft 5 panel script (design-field 1.x, GroupInput.php).

const sheets = new Map();
let frame = null;

/**
 * @param {string} url The site's built stylesheet
 * @returns {Promise<CSSStyleSheet>}
 */
export function siteSheet(url) {
    if (!sheets.has(url)) {
        sheets.set(url, fetch(url, {credentials: 'same-origin'})
            .then((response) => {
                if (!response.ok) {
                    throw new Error(`${response.status} ${url}`);
                }

                return response.text();
            })
            .then((css) => {
                // Tailwind's typed defaults (@property --tw-shadow…) only register at document level.
                const props = css.match(/@property\s+--[\w-]+\s*\{[^}]*\}/g);

                if (props) {
                    const registered = new CSSStyleSheet();
                    registered.replaceSync(props.join(''));
                    document.adoptedStyleSheets = [...document.adoptedStyleSheets, registered];
                }

                // The theme (--radius-xl: var(--brand-radius-xl)…) is declared on :root,:host; declare
                // it on the card's root too, which carries the brand attributes, so it resolves there.
                const sheet = new CSSStyleSheet();
                sheet.replaceSync(css.replace(/@import[^;]+;/g, '').split(':root,:host{').join(':root,:host,.df-r-root{'));

                return sheet;
            }));
    }

    return sheets.get(url);
}

/**
 * The card's own frame: drawn at twice the size and scaled to half, so a radius reads
 * against a card's proportions.
 *
 * @returns {CSSStyleSheet}
 */
export function tileFrame() {
    if (!frame) {
        frame = new CSSStyleSheet();
        frame.replaceSync(':host{display:block;width:100%;overflow:hidden}'
            + '.df-r-root{display:flex;align-items:center;justify-content:center;width:100%;height:52px;background:transparent}'
            + '.df-r-card{flex:none;display:flex;flex-direction:column;justify-content:flex-end;gap:6px;width:136px;height:80px;padding:12px;box-sizing:border-box;transform:scale(.5)}'
            + '.df-r-card i{display:block;height:6px;width:70%;border-radius:3px;background:currentColor;opacity:.4}'
            + '.df-r-card i+i{width:45%}');
    }

    return frame;
}
