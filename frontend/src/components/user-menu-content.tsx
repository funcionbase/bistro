import { AppLink } from '@/components/app-link';
import { DropdownMenuGroup, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator } from '@/components/ui/dropdown-menu';
import { UserInfo } from '@/components/user-info';
import { useMobileNavigation } from '@/hooks/use-mobile-navigation';
import { route } from '@/lib/route-compat';
import { useLogout } from '@/lib/use-logout';
import { type User } from '@/types';
import { LogOut, Settings } from 'lucide-react';

interface UserMenuContentProps {
    user: User;
}

export function UserMenuContent({ user }: UserMenuContentProps) {
    const cleanup = useMobileNavigation();
    const logout = useLogout();

    return (
        <>
            <DropdownMenuLabel className="p-0 font-normal">
                <div className="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
                    <UserInfo user={user} showEmail={true} />
                </div>
            </DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuGroup>
                <DropdownMenuItem asChild>
                    <AppLink className="block w-full" href={route('profile.edit')} prefetch onClick={cleanup}>
                        <Settings className="mr-2" />
                        Configuración
                    </AppLink>
                </DropdownMenuItem>
            </DropdownMenuGroup>
            <DropdownMenuSeparator />
            <DropdownMenuItem
                onClick={() => {
                    cleanup();
                    logout();
                }}
            >
                <LogOut className="mr-2" />
                Cerrar sesión
            </DropdownMenuItem>
        </>
    );
}
