import React, { useState, useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { ApplicationStore } from '@/state';
import { useStoreActions, useStoreState } from 'easy-peasy';
import updateAccountFont from '@/api/account/updateAccountFont';
import Select from '@/reviactyl/elements/Select';

interface FontInfo {
    name: string;
    family: string;
}

const FONT_LIST: Record<string, FontInfo> = {
    '': { name: 'Default', family: '' },
    Poppins: { name: 'Poppins', family: 'Poppins' },
    Inter: { name: 'Inter', family: 'Inter' },
    Roboto: { name: 'Roboto', family: 'Roboto' },
    Cairo: { name: 'Cairo', family: 'Cairo' },
    'Google+Sans': { name: 'Google Sans', family: 'Google Sans' },
    'Playpen+Sans+Arabic': { name: 'Playpen Sans Arabic', family: 'Playpen Sans Arabic' },
    'Noto+Sans': { name: 'Noto Sans', family: 'Noto Sans' },
    'IBM+Plex+Sans': { name: 'IBM Plex Sans', family: 'IBM Plex Sans' },
    'JetBrains+Mono': { name: 'JetBrains Mono', family: 'JetBrains Mono' },
    'Source+Code+Pro': { name: 'Source Code Pro', family: 'Source Code Pro' },
    'Fira+Code': { name: 'Fira Code', family: 'Fira Code' },
    Montserrat: { name: 'Montserrat', family: 'Montserrat' },
    'Open+Sans': { name: 'Open Sans', family: 'Open Sans' },
    Lato: { name: 'Lato', family: 'Lato' },
    Raleway: { name: 'Raleway', family: 'Raleway' },
    Nunito: { name: 'Nunito', family: 'Nunito' },
    'Press+Start+2P': { name: 'Press Start 2P', family: 'Press Start 2P' },
    VT323: { name: 'VT323', family: 'VT323' },
    Silkscreen: { name: 'Silkscreen', family: 'Silkscreen' },
    DotGothic16: { name: 'DotGothic16', family: 'DotGothic16' },
};

const loadGoogleFont = (fontKey: string) => {
    if (!fontKey || fontKey === '') return;

    const id = `font-${fontKey}`;
    if (document.getElementById(id)) return;

    const link = document.createElement('link');
    link.id = id;
    link.rel = 'stylesheet';
    link.href = `//fonts.googleapis.com/css2?family=${fontKey}:ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,100;1,200;1,300;1,400;1,500;1,600;1,700;1,800;1,900&display=swap`;
    document.head.appendChild(link);
};

const applyFont = (fontKey: string | null) => {
    if (typeof document === 'undefined') return;

    const info = fontKey ? FONT_LIST[fontKey] : null;
    const value = info?.family
        ? `"${info.family}", sans-serif`
        : '';

    if (value) {
        document.documentElement.style.setProperty('--font-family', value);
    } else {
        document.documentElement.style.removeProperty('--font-family');
    }
};

const FontSwitcher = () => {
    const { t } = useTranslation('dashboard/account');
    const user = useStoreState((state: ApplicationStore) => state.user.data);
    const setUserData = useStoreActions((actions: any) => actions.user.setUserData);
    const [fonts, setFonts] = useState<Record<string, FontInfo>>({});
    const [currentFont, setCurrentFont] = useState(user?.font || '');

    useEffect(() => {
        fetch('/fonts/list.json')
            .then((res) => res.json())
            .then((data) => setFonts(data))
            .catch(() => setFonts(FONT_LIST));

        if (user?.font) {
            loadGoogleFont(user.font);
            applyFont(user.font);
        }
    }, []);

    const handleChange = async (e: React.ChangeEvent<HTMLSelectElement>) => {
        const newFont = e.target.value;
        setCurrentFont(newFont);

        const fontKey = newFont || '';

        if (fontKey) {
            loadGoogleFont(fontKey);
        }
        applyFont(fontKey || null);

        if (user) {
            try {
                await updateAccountFont({ font: fontKey });
                setUserData({ ...user, font: fontKey || null });
            } catch (error) {
                console.error('Failed to update font:', error);
            }
        }
    };

    const fontEntries = Object.keys(fonts).length > 0 ? fonts : FONT_LIST;

    return (
        <div className='flex flex-col gap-2 mb-2 sm:flex-row sm:justify-between sm:items-center'>
            <p className='min-w-0 flex-1'>{t('overview.font')}</p>
            <Select className='!pr-15 w-full min-w-0 sm:!w-auto' value={currentFont} onChange={handleChange}>
                {Object.entries(fontEntries).map(([key, info]) => (
                    <option key={key} value={key}>
                        {info.name}
                    </option>
                ))}
            </Select>
        </div>
    );
};

export default FontSwitcher;

export const FontLoader = () => {
    const user = useStoreState((state: any) => state.user.data);

    useEffect(() => {
        if (user?.font) {
            loadGoogleFont(user.font);
            applyFont(user.font);
        }
    }, [user?.font]);

    return null;
};
