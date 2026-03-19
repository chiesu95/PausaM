<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
    },
];

defineProps<{
    stats: {
        totalPlayers: number;
        totalRounds: number;
        totalSessions: number;
        totalBets: number;
    };
    activeSession: {
        personName: string;
        startedAt: string;
    } | null;
    openRound: {
        id: number;
        telegramChatId: string;
        betsCount: number;
        createdAt: string;
    } | null;
    leaderboard: Array<{
        id: number;
        fullName: string;
        username: string | null;
        points: number;
        wins: number;
        totalBets: number;
    }>;
    recentRounds: Array<{
        id: number;
        resultLabel: string | null;
        resolvedAt: string | null;
        durationMinutes: number | null;
        winners: string[];
        betsCount: number;
    }>;
}>();

const formatDate = (date: string | null) => {
    if (!date) {
        return '-';
    }

    return new Date(date).toLocaleString('it-IT', {
        dateStyle: 'short',
        timeStyle: 'short',
    });
};
</script>

<template>
    <Head title="Bathroom Bet Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                    <p class="text-sm text-muted-foreground">Giocatori</p>
                    <p class="mt-2 text-2xl font-semibold">{{ stats.totalPlayers }}</p>
                </div>
                <div class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                    <p class="text-sm text-muted-foreground">Round risolti</p>
                    <p class="mt-2 text-2xl font-semibold">{{ stats.totalRounds }}</p>
                </div>
                <div class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                    <p class="text-sm text-muted-foreground">Sessioni concluse</p>
                    <p class="mt-2 text-2xl font-semibold">{{ stats.totalSessions }}</p>
                </div>
                <div class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                    <p class="text-sm text-muted-foreground">Puntate totali</p>
                    <p class="mt-2 text-2xl font-semibold">{{ stats.totalBets }}</p>
                </div>
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                <div class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                    <h2 class="text-lg font-semibold">Sessione bagno attiva</h2>
                    <p v-if="activeSession" class="mt-2 text-sm">
                        <span class="font-medium">{{ activeSession.personName }}</span> dal
                        {{ formatDate(activeSession.startedAt) }}
                    </p>
                    <p v-else class="mt-2 text-sm text-muted-foreground">Nessuna sessione attiva.</p>
                </div>

                <div class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                    <h2 class="text-lg font-semibold">Round aperto</h2>
                    <div v-if="openRound" class="mt-2 text-sm">
                        <p>ID round: {{ openRound.id }}</p>
                        <p>Chat ID: {{ openRound.telegramChatId }}</p>
                        <p>Puntate: {{ openRound.betsCount }}</p>
                        <p>Aperto: {{ formatDate(openRound.createdAt) }}</p>
                    </div>
                    <p v-else class="mt-2 text-sm text-muted-foreground">Nessun round aperto.</p>
                </div>
            </div>

            <div class="grid gap-4 xl:grid-cols-2">
                <div class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                    <h2 class="text-lg font-semibold">Classifica punti</h2>
                    <div v-if="leaderboard.length" class="mt-4 overflow-x-auto">
                        <table class="w-full min-w-[480px] text-sm">
                            <thead>
                                <tr class="border-b border-sidebar-border/70 text-left text-muted-foreground dark:border-sidebar-border">
                                    <th class="pb-2">#</th>
                                    <th class="pb-2">Utente</th>
                                    <th class="pb-2">Punti</th>
                                    <th class="pb-2">Vinte</th>
                                    <th class="pb-2">Puntate</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(player, index) in leaderboard" :key="player.id" class="border-b border-sidebar-border/50 last:border-0 dark:border-sidebar-border/60">
                                    <td class="py-2">{{ index + 1 }}</td>
                                    <td class="py-2">
                                        {{ player.username ? `@${player.username}` : player.fullName }}
                                    </td>
                                    <td class="py-2 font-semibold">{{ player.points }}</td>
                                    <td class="py-2">{{ player.wins }}</td>
                                    <td class="py-2">{{ player.totalBets }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p v-else class="mt-2 text-sm text-muted-foreground">Nessun dato disponibile.</p>
                </div>

                <div class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                    <h2 class="text-lg font-semibold">Ultimi round risolti</h2>
                    <div v-if="recentRounds.length" class="mt-4 space-y-3 text-sm">
                        <div v-for="round in recentRounds" :key="round.id" class="rounded-lg border border-sidebar-border/60 p-3 dark:border-sidebar-border">
                            <p class="font-medium">
                                Round #{{ round.id }} · {{ round.resultLabel ?? 'Esito non disponibile' }}
                            </p>
                            <p class="text-muted-foreground">
                                Durata: {{ round.durationMinutes ?? '-' }} min · Puntate: {{ round.betsCount }}
                            </p>
                            <p class="text-muted-foreground">Risolto: {{ formatDate(round.resolvedAt) }}</p>
                            <p class="mt-1">
                                Vincitori:
                                {{ round.winners.length ? round.winners.join(', ') : 'Nessuno' }}
                            </p>
                        </div>
                    </div>
                    <p v-else class="mt-2 text-sm text-muted-foreground">Nessun round risolto finora.</p>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
