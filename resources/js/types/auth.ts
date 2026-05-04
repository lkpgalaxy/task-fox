export type User = {
    id: number;
    name: string;
    email: string;
    github_username?: string | null;
    has_github_token?: boolean;
    role: 'admin' | 'user';
    disabled_at?: string | null;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown; // This allows for additional properties...
};

export type Auth = {
    user: User | null;
};
