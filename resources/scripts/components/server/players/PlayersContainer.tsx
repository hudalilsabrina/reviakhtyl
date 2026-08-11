import { useEffect, useState } from 'react';
import tw from 'twin.macro';
import styled from 'styled-components';
import { useTranslation } from 'react-i18next';
import { Actions, useStoreActions } from 'easy-peasy';
import { FaGavel, FaListUl, FaTrash, FaUserShield, FaUserPlus, FaUsers, FaCircle } from 'react-icons/fa6';
import ServerContentBlock from '@/reviactyl/elements/ServerContentBlock';
import Spinner from '@/reviactyl/elements/Spinner';
import ConfirmationModal from '@/reviactyl/elements/ConfirmationModal';
import FlashMessageRender from '@/components/FlashMessageRender';
import { ServerContext } from '@/state/server';
import { ApplicationStore } from '@/state';
import { httpErrorToHuman } from '@/api/http';
import { usePermissions } from '@/plugins/usePermissions';
import {
    banPlayer,
    deopPlayer,
    getOnlinePlayers,
    getPlayerStatus,
    opPlayer,
    PlayerEntry,
    PlayerStatus,
    unbanPlayer,
    whitelistAdd,
    whitelistRemove,
} from '@/api/server/players/players';

/* ---------------------------------------------------------------------------
 * Minecraft-themed primitives (kept minimal — matches DatapacksContainer tone)
 * ------------------------------------------------------------------------- */

const GRASS = ['#5d8a3c', '#5d8a3c', '#76a24e', '#5d8a3c', '#4a7432', '#76a24e', '#5d8a3c', '#6b9a47'];

const StonePanel = styled.div`
    ${tw`bg-[#1a1d22] border border-black/70 shadow-[inset_0_1px_0_rgba(255,255,255,0.05),0_10px_30px_-12px_rgba(0,0,0,0.8)] rounded-[4px]`};
`;

const GrassStrip = styled.div`
    height: 10px;
    background-image: repeating-linear-gradient(90deg, ${GRASS.join(', 0 12.5%, ')}, 0 12.5%);
`;

const MinecraftButton = styled.button<{ $tone?: 'primary' | 'danger' | 'success' }>`
    ${tw`relative inline-flex items-center justify-center gap-1.5 text-sm font-semibold text-white select-none`}
    ${tw`px-4 h-8 border-2`}
    image-rendering: pixelated;
    transition: filter 120ms ease, transform 60ms ease;
    border-color: #1a1d22;
    background-color: ${({ $tone }) => ($tone === 'danger' ? '#8a3c3c' : $tone === 'success' ? '#4c6f3f' : '#3b3f4a')};
    background-image: linear-gradient(180deg, rgba(255, 255, 255, 0.25), rgba(255, 255, 255, 0) 45%),
        linear-gradient(180deg, transparent 92%, rgba(0, 0, 0, 0.4));
    box-shadow: inset 0 2px 0 rgba(255, 255, 255, 0.22), inset 0 -2px 0 rgba(0, 0, 0, 0.28), 0 2px 0 rgba(0, 0, 0, 0.35);
    border-radius: 2px;

    &:hover:not(:disabled) {
        filter: brightness(1.12);
    }

    &:active:not(:disabled) {
        transform: translateY(1px);
    }

    &:disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }
`;

const Row = styled.div`
    ${tw`flex items-center gap-3 px-4 py-3 border-b border-black/40 text-sm`}
    &:last-of-type {
        border-bottom: none;
    }
`;

const Empty = styled.div`
    ${tw`text-center text-gray-500 text-sm py-10`}
`;

/* ---------------------------------------------------------------------------
 * Component
 * ------------------------------------------------------------------------- */

type Tab = 'whitelist' | 'ops' | 'bans' | 'online';

const PlayersContainer = () => {
    const { t } = useTranslation('server/players');
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const { addError, addFlash, clearFlashes } = useStoreActions(
        (actions: Actions<ApplicationStore>) => actions.flashes
    );
    const [canManage] = usePermissions(['player.manage']);

    const [tab, setTab] = useState<Tab>('whitelist');
    const [loading, setLoading] = useState(true);
    const [status, setStatus] = useState<PlayerStatus | null>(null);
    const [onlinePlayers, setOnlinePlayers] = useState<string[]>([]);
    const [busy, setBusy] = useState(false);

    const [addName, setAddName] = useState('');
    const [addReason, setAddReason] = useState('');
    const [opLevel, setOpLevel] = useState(4);

    const [confirm, setConfirm] = useState<{ action: 'remove' | 'unban' | 'deop'; list: Tab; name: string } | null>(
        null
    );

    const refresh = () => {
        setLoading(true);
        Promise.all([getPlayerStatus(uuid), getOnlinePlayers(uuid)])
            .then(([s, online]) => {
                setStatus(s);
                setOnlinePlayers(online.online ? online.players : []);
            })
            .catch((error) => addError({ key: 'server:players', message: httpErrorToHuman(error) }))
            .finally(() => setLoading(false));
    };

    useEffect(() => {
        clearFlashes('server:players');
        refresh();
    }, []);

    const withBusy = async (fn: () => Promise<void>, successKey?: string, name?: string) => {
        setBusy(true);
        try {
            await fn();
            if (successKey && name) {
                addFlash({ key: 'server:players', type: 'success', message: t(successKey, { name }) });
            }
            refresh();
        } catch (error) {
            addError({ key: 'server:players', message: httpErrorToHuman(error) });
        } finally {
            setBusy(false);
        }
    };

    const submitAdd = () => {
        const name = addName.trim();
        if (!/^[A-Za-z0-9_]{1,16}$/.test(name)) {
            addError({ key: 'server:players', message: t('error_username') });
            return;
        }

        if (tab === 'whitelist') {
            withBusy(() => whitelistAdd(uuid, name), 'added_whitelist', name);
        } else if (tab === 'ops') {
            withBusy(() => opPlayer(uuid, name, opLevel), 'added_op', name);
        } else if (tab === 'bans') {
            withBusy(() => banPlayer(uuid, name, addReason || undefined), 'banned', name);
        }
        setAddName('');
        setAddReason('');
    };

    const runConfirm = () => {
        if (!confirm) return;
        const { action, name } = confirm;
        const fn =
            action === 'remove'
                ? () => whitelistRemove(uuid, name)
                : action === 'deop'
                ? () => deopPlayer(uuid, name)
                : () => unbanPlayer(uuid, name);
        setBusy(true);
        fn()
            .then(() => {
                const successKey =
                    action === 'remove' ? 'removed_whitelist' : action === 'deop' ? 'removed_op' : 'unbanned';
                addFlash({ key: 'server:players', type: 'success', message: t(successKey, { name }) });
                refresh();
            })
            .catch((error) => addError({ key: 'server:players', message: httpErrorToHuman(error) }))
            .finally(() => setBusy(false));
        setConfirm(null);
    };

    const listFor = (tabKey: Tab): PlayerEntry[] => {
        if (!status) return [];
        return tabKey === 'whitelist' ? status.whitelist : tabKey === 'ops' ? status.ops : status.bans;
    };

    const tabIcon = (tabKey: Tab) =>
        tabKey === 'whitelist' ? (
            <FaListUl css={tw`text-[11px]`} />
        ) : tabKey === 'ops' ? (
            <FaUserShield css={tw`text-[11px]`} />
        ) : tabKey === 'bans' ? (
            <FaGavel css={tw`text-[11px]`} />
        ) : (
            <FaUsers css={tw`text-[11px]`} />
        );

    const isOnline = status?.online ?? false;

    return (
        <ServerContentBlock title={t('title')}>
            <FlashMessageRender byKey={'server:players'} css={tw`mb-4`} />

            {/* Server status banner */}
            <StonePanel css={tw`mb-4 p-4`}>
                <div css={tw`flex items-center gap-2`}>
                    <FaCircle css={tw`text-[10px]`} style={{ color: isOnline ? '#4ade80' : '#6b7280' }} />
                    <span css={tw`text-sm font-semibold text-gray-100`}>
                        {isOnline ? t('server_online') : t('server_offline')}
                    </span>
                </div>
                <p css={tw`text-xs text-gray-400 mt-1`}>{isOnline ? t('note_online') : t('note_offline')}</p>
            </StonePanel>

            {/* Tabs */}
            <div css={tw`flex flex-wrap items-center gap-2 mb-4`}>
                {(['whitelist', 'ops', 'bans', 'online'] as Tab[]).map((key) => (
                    <MinecraftButton
                        key={key}
                        onClick={() => setTab(key)}
                        $tone={tab === key ? 'primary' : undefined}
                        css={tab === key ? undefined : tw`opacity-80`}
                    >
                        {tabIcon(key)}
                        {t(key)}
                        {key !== 'online' && status && ` (${listFor(key).length})`}
                    </MinecraftButton>
                ))}
            </div>

            {loading ? (
                <StonePanel css={tw`p-10 flex justify-center`}>
                    <Spinner size={'large'} />
                </StonePanel>
            ) : (
                <>
                    {/* Add player form (hidden on online tab) */}
                    {tab !== 'online' && canManage && (
                        <StonePanel css={tw`p-4 mb-4`}>
                            <GrassStrip css={tw`-mx-4 -mt-4 mb-3 rounded-t-[4px]`} />
                            <div css={tw`flex flex-wrap gap-2 items-end`}>
                                <input
                                    type='text'
                                    value={addName}
                                    onChange={(e) => setAddName(e.target.value)}
                                    onKeyDown={(e) => e.key === 'Enter' && submitAdd()}
                                    placeholder={t('player_name_placeholder')}
                                    disabled={busy}
                                    css={tw`flex-1 min-w-[160px] px-3 h-9 rounded bg-black/40 border border-white/10 text-sm text-gray-100 placeholder:text-gray-500 focus:border-cyan-400/50 focus:outline-none`}
                                />
                                {tab === 'ops' && !isOnline && (
                                    <select
                                        value={opLevel}
                                        onChange={(e) => setOpLevel(Number(e.target.value))}
                                        disabled={busy}
                                        css={tw`px-3 h-9 rounded bg-black/40 border border-white/10 text-sm text-gray-100`}
                                    >
                                        {[1, 2, 3, 4].map((l) => (
                                            <option key={l} value={l}>
                                                {t('level')} {l}
                                            </option>
                                        ))}
                                    </select>
                                )}
                                {tab === 'bans' && (
                                    <input
                                        type='text'
                                        value={addReason}
                                        onChange={(e) => setAddReason(e.target.value)}
                                        placeholder={t('reason_placeholder')}
                                        disabled={busy}
                                        css={tw`flex-1 min-w-[160px] px-3 h-9 rounded bg-black/40 border border-white/10 text-sm text-gray-100 placeholder:text-gray-500 focus:border-cyan-400/50 focus:outline-none`}
                                    />
                                )}
                                <MinecraftButton
                                    onClick={submitAdd}
                                    disabled={busy || !addName.trim()}
                                    $tone={'success'}
                                >
                                    <FaUserPlus css={tw`text-[11px]`} />
                                    {t(
                                        tab === 'whitelist'
                                            ? 'add_to_whitelist'
                                            : tab === 'ops'
                                            ? 'op_player'
                                            : 'ban_player'
                                    )}
                                </MinecraftButton>
                            </div>
                        </StonePanel>
                    )}

                    {/* Online tab */}
                    {tab === 'online' ? (
                        <StonePanel>
                            {!isOnline ? (
                                <Empty>{t('offline_player_list')}</Empty>
                            ) : onlinePlayers.length === 0 ? (
                                <Empty>{t('no_online')}</Empty>
                            ) : (
                                onlinePlayers.map((name) => (
                                    <Row key={name}>
                                        <FaCircle css={tw`text-[8px]`} style={{ color: '#4ade80' }} />
                                        <span css={tw`text-gray-100 font-medium`}>{name}</span>
                                    </Row>
                                ))
                            )}
                        </StonePanel>
                    ) : status && listFor(tab).length === 0 ? (
                        <StonePanel>
                            <Empty>
                                {t(tab === 'whitelist' ? 'no_whitelist' : tab === 'ops' ? 'no_ops' : 'no_bans')}
                            </Empty>
                        </StonePanel>
                    ) : (
                        <StonePanel>
                            {status &&
                                listFor(tab).map((entry) => (
                                    <Row key={entry.uuid}>
                                        <div css={tw`flex-1 min-w-0`}>
                                            <div css={tw`text-gray-100 font-medium truncate`}>{entry.name}</div>
                                            <div css={tw`text-xs text-gray-500 truncate`}>{entry.uuid}</div>
                                            {tab === 'ops' && (
                                                <div css={tw`text-xs text-amber-300/80`}>
                                                    {t('level')} {entry.level}
                                                </div>
                                            )}
                                            {tab === 'bans' && entry.reason && (
                                                <div css={tw`text-xs text-gray-400 truncate`}>{entry.reason}</div>
                                            )}
                                        </div>
                                        {canManage && (
                                            <MinecraftButton
                                                $tone={tab === 'bans' ? undefined : 'danger'}
                                                disabled={busy}
                                                onClick={() =>
                                                    setConfirm({
                                                        action:
                                                            tab === 'bans'
                                                                ? 'unban'
                                                                : tab === 'ops'
                                                                ? 'deop'
                                                                : 'remove',
                                                        list: tab,
                                                        name: entry.name,
                                                    })
                                                }
                                            >
                                                <FaTrash css={tw`text-[11px]`} />
                                                {t(tab === 'bans' ? 'unban' : tab === 'ops' ? 'deop' : 'remove')}
                                            </MinecraftButton>
                                        )}
                                    </Row>
                                ))}
                        </StonePanel>
                    )}
                </>
            )}

            <ConfirmationModal
                visible={!!confirm}
                title={t('remove')}
                buttonText={t('remove')}
                onConfirmed={runConfirm}
                showSpinnerOverlay={busy}
                onModalDismissed={() => setConfirm(null)}
            >
                {confirm &&
                    t('confirm_remove', {
                        name: confirm.name,
                        list: t(confirm.list),
                    })}
            </ConfirmationModal>
        </ServerContentBlock>
    );
};

export default PlayersContainer;
