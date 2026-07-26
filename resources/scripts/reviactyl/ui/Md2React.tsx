import React from 'react';

interface Md2ReactProps {
    markdown: string;
}

const ALLOWED_PROTOCOLS = ['http:', 'https:', 'mailto:'];

/**
 * Markdown reaching this component can be attacker influenced — the assistant quotes game
 * server files and logs back to the user, and anyone with access to a server can write those.
 * React refuses to render `javascript:` hrefs on its own, but that is an implementation detail
 * of React rather than a guarantee we make, so the allowlist is enforced here as well.
 *
 * Returns the href to render, or null when the URL is not something we are willing to link to.
 */
const safeHref = (url: string): string | null => {
    // Browsers strip tabs, newlines and surrounding whitespace out of URLs before parsing them,
    // so a tab wedged into "javascript:" or a leading space in front of it still reaches the
    // network layer as `javascript:`. Normalise the same way before testing, otherwise the
    // check is trivially bypassed by anyone who knows to insert a control character.
    const normalized = Array.from(url.trim())
        .filter((character) => {
            const code = character.charCodeAt(0);

            return code > 0x1f && code !== 0x7f;
        })
        .join('');

    if (normalized.length === 0) return null;

    try {
        return ALLOWED_PROTOCOLS.includes(new URL(normalized).protocol) ? normalized : null;
    } catch {
        // Not parseable as an absolute URL. If it carries no scheme at all it is a relative
        // reference resolved against the panel itself, which is always safe to link to.
        return /^[a-z][a-z0-9+.-]*:/i.test(normalized) ? null : normalized;
    }
};

const parseBold = (text: string, keyPrefix: string): (string | React.ReactElement)[] => {
    const boldRegex = /\*\*(.*?)\*\*/g;
    const result: (string | React.ReactElement)[] = [];
    let lastIndex = 0;
    let match;

    while ((match = boldRegex.exec(text)) !== null) {
        if (match.index > lastIndex) {
            result.push(text.slice(lastIndex, match.index));
        }
        // The prefix keeps keys unique across the several segments that are parsed into a
        // single output array; the match index alone repeats between them.
        result.push(<strong key={`${keyPrefix}-bold-${match.index}`}>{match[1]}</strong>);
        lastIndex = boldRegex.lastIndex;
    }

    if (lastIndex < text.length) {
        result.push(text.slice(lastIndex));
    }

    return result;
};

const Md2React = ({ markdown }: Md2ReactProps) => {
    const linkRegex = /\[([^\]]+)\]\(([^)]+)\)/g;
    const parts: (string | React.ReactElement)[] = [];
    let lastIndex = 0;
    let match;

    while ((match = linkRegex.exec(markdown)) !== null) {
        const textBefore = markdown.substring(lastIndex, match.index);
        parts.push(...parseBold(textBefore, `before-${match.index}`));

        const label = match[1] ?? '';
        const href = safeHref(match[2] ?? '');

        if (href === null) {
            // Keep what the author wrote visible, just without anything to click on.
            parts.push(label);
        } else {
            parts.push(
                <a
                    href={href}
                    key={`link-${match.index}`}
                    className='font-semibold'
                    target='_blank'
                    rel='noopener noreferrer'
                >
                    {label}
                </a>
            );
        }

        lastIndex = match.index + match[0].length;
    }

    const textAfter = markdown.substring(lastIndex);
    if (textAfter) {
        parts.push(...parseBold(textAfter, 'after'));
    }

    return <>{parts}</>;
};

export default Md2React;
