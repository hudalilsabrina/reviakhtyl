import type { ComponentType } from 'react';
import { lazy } from 'react';
import ServerConsole from '@/components/server/console/ServerConsoleContainer';
import DatabasesContainer from '@/components/server/databases/DatabasesContainer';
import ScheduleContainer from '@/components/server/schedules/ScheduleContainer';
import UsersContainer from '@/components/server/users/UsersContainer';
import BackupContainer from '@/components/server/backups/BackupContainer';
import PluginsContainer from '@/components/server/plugins/PluginsContainer';
import ModsContainer from '@/components/server/mods/ModsContainer';
import SplitterContainer from '@/components/server/splitter/SplitterContainer';
import NetworkContainer from '@/components/server/network/NetworkContainer';
import StartupContainer from '@/components/server/startup/StartupContainer';
import SubdomainContainer from '@/components/server/subdomain/SubdomainContainer';
import PropertiesContainer from '@/components/server/properties/PropertiesContainer';
import FileManagerContainer from '@/components/server/files/FileManagerContainer';
import SettingsContainer from '@/components/server/settings/SettingsContainer';
import AccountOverviewContainer from '@/components/dashboard/AccountOverviewContainer';
import AccountApiContainer from '@/components/dashboard/AccountApiContainer';
import AccountSSHContainer from '@/components/dashboard/ssh/AccountSSHContainer';
import AccountTelegramContainer from '@/components/dashboard/AccountTelegramContainer';
import ActivityLogContainer from '@/components/dashboard/activity/ActivityLogContainer';
import ServerActivityLogContainer from '@/components/server/ServerActivityLogContainer';
import ChatContainer from '@/components/server/chat/ChatContainer';
import {
    FaBoltLightning,
    FaBoxArchive,
    FaCalendar,
    FaChartLine,
    FaCodeBranch,
    FaDatabase,
    FaEye,
    FaFolder,
    FaGear,
    FaGlobe,
    FaKey,
    FaLock,
    FaPlay,
    FaSliders,
    FaCube,
    FaPuzzlePiece,
    FaTelegram,
    FaTerminal,
    FaUser,
    FaUsers,
    FaWandMagicSparkles,
} from 'react-icons/fa6';

// Each of the router files is already code split out appropriately — so
// all of the items above will only be loaded in when that router is loaded.
//
// These specific lazy loaded routes are to avoid loading in heavy screens
// for the server dashboard when they're only needed for specific instances.
const FileEditContainer = lazy(() => import('@/components/server/files/FileEditContainer'));
const ScheduleEditContainer = lazy(() => import('@/components/server/schedules/ScheduleEditContainer'));
const HistoricalGraphsContainer = lazy(() => import('@/components/server/metrics/HistoricalGraphsContainer'));

interface RouteDefinition {
    route: string;
    path?: string;
    // If undefined is passed this route is still rendered into the router itself
    // but no navigation link is displayed in the sub-navigation menu.
    name: string | undefined;
    // Fallback shown in the navigation when the translation key in `name` has not been
    // defined for the active locale yet.
    label?: string;
    component: ComponentType;
    end?: boolean;
    icon?: ComponentType<React.SVGProps<SVGSVGElement>>;
}

interface ServerRouteDefinition extends RouteDefinition {
    permission?: string | string[];
    nestId?: number;
    eggId?: number;
    nestIds?: number[];
    eggIds?: number[];
    eggFeature?: string;
}

interface Routes {
    // All of the routes available under "/account"
    account: RouteDefinition[];
    // All of the routes available under "/server/:id"
    server: {
        control: ServerRouteDefinition[];
        management: ServerRouteDefinition[];
        administration: ServerRouteDefinition[];
    };
}

export default {
    account: [
        {
            route: '',
            path: '',
            name: 'account.overview',
            component: AccountOverviewContainer,
            icon: FaUser,
            end: true,
        },
        {
            route: 'api',
            name: 'account.api',
            icon: FaLock,
            component: AccountApiContainer,
        },
        {
            route: 'ssh',
            name: 'account.ssh',
            icon: FaKey,
            component: AccountSSHContainer,
        },
        {
            route: 'telegram',
            name: 'account.telegram',
            icon: FaTelegram,
            component: AccountTelegramContainer,
        },
        {
            route: 'activity',
            name: 'account.activity',
            icon: FaEye,
            component: ActivityLogContainer,
        },
    ],
    server: {
        control: [
            {
                route: '',
                path: '',
                permission: null,
                name: 'server.console',
                component: ServerConsole,
                icon: FaTerminal,
                end: true,
            },
            {
                route: 'files/*',
                permission: 'file.*',
                name: 'server.files',
                component: FileManagerContainer,
                icon: FaFolder,
            },
            {
                route: 'files/edit/*',
                permission: 'file.*',
                name: undefined,
                component: FileEditContainer,
            },
            {
                route: 'files/new/*',
                permission: 'file.*',
                name: undefined,
                component: FileEditContainer,
            },
            {
                route: 'startup/*',
                permission: 'startup.*',
                name: 'server.startup',
                component: StartupContainer,
                icon: FaPlay,
            },
            {
                route: 'properties/*',
                permission: 'properties.*',
                eggFeature: 'properties',
                name: 'server.properties',
                component: PropertiesContainer,
                icon: FaSliders,
            },
            {
                route: 'network/*',
                permission: 'allocation.*',
                name: 'server.network',
                component: NetworkContainer,
                icon: FaBoltLightning,
            },
            {
                route: 'subdomain/*',
                permission: 'subdomain.*',
                eggFeature: 'subdomain',
                name: 'server.subdomain',
                component: SubdomainContainer,
                icon: FaGlobe,
            },
            {
                route: 'metrics/*',
                permission: null,
                name: 'server.metrics',
                component: HistoricalGraphsContainer,
                icon: FaChartLine,
            },
            {
                route: 'chat',
                permission: null,
                name: 'server.chat',
                label: 'Assistant',
                component: ChatContainer,
                icon: FaWandMagicSparkles,
            },
        ],
        management: [
            {
                route: 'databases/*',
                permission: 'database.*',
                name: 'server.databases',
                component: DatabasesContainer,
                icon: FaDatabase,
            },
            {
                route: 'schedules/*',
                permission: 'schedule.*',
                name: 'server.schedules',
                component: ScheduleContainer,
                icon: FaCalendar,
            },
            {
                route: 'schedules/:id/*',
                permission: 'schedule.*',
                name: undefined,
                component: ScheduleEditContainer,
            },
            {
                route: 'backups/*',
                permission: 'backup.*',
                name: 'server.backups',
                component: BackupContainer,
                icon: FaBoxArchive,
            },
            {
                route: 'plugins/*',
                permission: 'plugin.*',
                eggFeature: 'plugins',
                name: 'server.plugins',
                component: PluginsContainer,
                icon: FaPuzzlePiece,
            },
            {
                route: 'mods/*',
                permission: 'mod.*',
                eggFeature: 'mods',
                name: 'server.mods',
                component: ModsContainer,
                icon: FaCube,
            },
            {
                route: 'splitter/*',
                permission: null,
                name: 'server.splitter',
                component: SplitterContainer,
                icon: FaCodeBranch,
            },
        ],
        administration: [
            {
                route: 'users/*',
                permission: 'user.*',
                name: 'server.users',
                component: UsersContainer,
                icon: FaUsers,
            },
            {
                route: 'settings/*',
                permission: ['settings.*', 'file.sftp'],
                name: 'server.settings',
                component: SettingsContainer,
                icon: FaGear,
            },
            {
                route: 'activity',
                permission: 'activity.*',
                name: 'server.activity',
                component: ServerActivityLogContainer,
                icon: FaEye,
            },
        ],
    },
} as Routes;
