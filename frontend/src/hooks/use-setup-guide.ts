import { apiClient } from '@/lib/api-client';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

export interface SetupStep {
    id: string;
    title: string;
    description: string;
    url: string;
    completed: boolean;
}

export interface SetupGuideData {
    steps: SetupStep[];
    dismissed: boolean;
    allDone: boolean;
}

export function useSetupGuide() {
    const queryClient = useQueryClient();

    const query = useQuery<SetupGuideData>({
        queryKey: ['setup-guide'],
        queryFn: ({ signal }) => apiClient.get<SetupGuideData>('/api/v1/company/setup-guide', { signal }),
        staleTime: 60_000,
        retry: false,
    });

    const dismiss = useMutation({
        mutationFn: () => apiClient.post<{ dismissed: boolean }>('/api/v1/company/setup-guide/dismiss'),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['setup-guide'] }),
    });

    return { ...query, dismiss };
}
