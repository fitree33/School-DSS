import type { ComponentProps } from 'react';

type IconProps = ComponentProps<'svg'>;

const iconProps: IconProps = {
    'aria-hidden': true,
    fill: 'none',
    stroke: 'currentColor',
    strokeLinecap: 'round',
    strokeLinejoin: 'round',
    strokeWidth: 1.8,
    viewBox: '0 0 24 24',
};

export const DashboardIcon = (props: IconProps) => (
    <svg {...iconProps} {...props}><path d="M4 4h6v7H4zM14 4h6v4h-6zM14 12h6v8h-6zM4 15h6v5H4z" /></svg>
);

export const ProjectsIcon = (props: IconProps) => (
    <svg {...iconProps} {...props}><path d="M4 7.5h16v12H4zM8 7.5V5h8v2.5M4 11h16M9.5 11v2h5v-2" /></svg>
);

export const BudgetIcon = (props: IconProps) => (
    <svg {...iconProps} {...props}><path d="M4 7h16v11H4zM4 10h16M7 15h3M15.5 14a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z" /></svg>
);

export const MenuIcon = (props: IconProps) => (
    <svg {...iconProps} {...props}><path d="M4 7h16M4 12h16M4 17h16" /></svg>
);

export const CloseIcon = (props: IconProps) => (
    <svg {...iconProps} {...props}><path d="m6 6 12 12M18 6 6 18" /></svg>
);

export const LogoutIcon = (props: IconProps) => (
    <svg {...iconProps} {...props}><path d="M10 5H5v14h5M14 8l4 4-4 4M8 12h10" /></svg>
);

export const SearchIcon = (props: IconProps) => (
    <svg {...iconProps} {...props}><circle cx="11" cy="11" r="6" /><path d="m16 16 4 4" /></svg>
);

export const PlusIcon = (props: IconProps) => (
    <svg {...iconProps} {...props}><path d="M12 5v14M5 12h14" /></svg>
);

export const ChevronLeftIcon = (props: IconProps) => (
    <svg {...iconProps} {...props}><path d="m15 18-6-6 6-6" /></svg>
);

export const ChevronRightIcon = (props: IconProps) => (
    <svg {...iconProps} {...props}><path d="m9 18 6-6-6-6" /></svg>
);

export const EditIcon = (props: IconProps) => (
    <svg {...iconProps} {...props}><path d="m4 20 4.5-1 10-10a2.1 2.1 0 0 0-3-3l-10 10L4 20ZM14 7l3 3" /></svg>
);

export const TrashIcon = (props: IconProps) => (
    <svg {...iconProps} {...props}><path d="M5 7h14M9 7V4h6v3M7 7l1 13h8l1-13M10 11v5M14 11v5" /></svg>
);

export const ArrowLeftIcon = (props: IconProps) => (
    <svg {...iconProps} {...props}><path d="m10 6-6 6 6 6M4 12h16" /></svg>
);

export const FolderIcon = (props: IconProps) => (
    <svg {...iconProps} {...props}><path d="M3 7h7l2 2h9v10H3zM3 7V5h7l2 2" /></svg>
);
