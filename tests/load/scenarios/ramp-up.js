import exec from 'k6/execution';
import { Rate } from 'k6/metrics';
import { loadFixtures } from '../lib/fixtures.js';
import { writeScenarioSummary } from '../lib/summary.js';
import { requestDashboard } from './dashboard-stats.js';
import { requestNotifications } from './notifications.js';
import { requestSeances } from './seances-list.js';

const fixtures = loadFixtures();

export const errors = new Rate('errors');

const DEFAULT_ENDPOINT_WEIGHTS = {
    seances: 0.34,
    notifications: 0.33,
    dashboard: 0.33,
};

const DEFAULT_STAGES = [
    { duration: '2m', target: 50 },
    { duration: '5m', target: 50 },
    { duration: '2m', target: 150 },
    { duration: '5m', target: 150 },
    { duration: '2m', target: 300 },
    { duration: '5m', target: 300 },
    { duration: '2m', target: 0 },
];

const DURATION_UNIT_TO_MS = { ms: 1, s: 1000, m: 60000, h: 3600000 };

function parseDurationToMs(durationString) {
    if (typeof durationString !== 'string' || durationString.trim() === '') {
        return NaN;
    }

    const segmentPattern = /(\d+(?:\.\d+)?)(ms|s|m|h)/g;
    let match;
    let totalMs = 0;
    let matched = false;

    while ((match = segmentPattern.exec(durationString)) !== null) {
        matched = true;
        totalMs += parseFloat(match[1]) * DURATION_UNIT_TO_MS[match[2]];
    }

    return matched ? totalMs : NaN;
}

function parseEndpointWeights() {
    const raw = __ENV.RAMP_ENDPOINT_WEIGHTS;
    if (!raw) {
        return DEFAULT_ENDPOINT_WEIGHTS;
    }

    try {
        const parsed = JSON.parse(raw);
        const keys = ['seances', 'notifications', 'dashboard'];
        const isValid =
            parsed &&
            typeof parsed === 'object' &&
            !Array.isArray(parsed) &&
            keys.every((key) => typeof parsed[key] === 'number' && Number.isFinite(parsed[key]) && parsed[key] >= 0) &&
            keys.reduce((sum, key) => sum + parsed[key], 0) > 0;

        return isValid ? parsed : DEFAULT_ENDPOINT_WEIGHTS;
    } catch {
        return DEFAULT_ENDPOINT_WEIGHTS;
    }
}

function parseStages() {
    const raw = __ENV.RAMP_STAGES_JSON;
    if (!raw) {
        return DEFAULT_STAGES;
    }

    try {
        const parsed = JSON.parse(raw);
        const isValid =
            Array.isArray(parsed) &&
            parsed.length > 0 &&
            parsed.every((stage) => stage && typeof stage.duration === 'string' && Number.isFinite(stage.target) && stage.target >= 0);

        return isValid ? parsed : DEFAULT_STAGES;
    } catch {
        return DEFAULT_STAGES;
    }
}

const ENDPOINT_WEIGHTS = parseEndpointWeights();
const STAGES = parseStages();
const RAMP_P95_THRESHOLD_MS = Number(__ENV.RAMP_P95_THRESHOLD_MS) || 300;
const RAMP_ERROR_THRESHOLD = Number(__ENV.RAMP_ERROR_THRESHOLD) || 0.05;

const ENDPOINT_MIX = [
    { name: 'seances', weight: ENDPOINT_WEIGHTS.seances, request: requestSeances },
    { name: 'notifications', weight: ENDPOINT_WEIGHTS.notifications, request: requestNotifications },
    { name: 'dashboard', weight: ENDPOINT_WEIGHTS.dashboard, request: requestDashboard },
];

const TOTAL_ENDPOINT_WEIGHT = ENDPOINT_MIX.reduce((sum, entry) => sum + entry.weight, 0);

function pickWeightedEndpoint() {
    let roll = Math.random() * TOTAL_ENDPOINT_WEIGHT;

    for (const entry of ENDPOINT_MIX) {
        if (roll < entry.weight) {
            return entry;
        }
        roll -= entry.weight;
    }

    return ENDPOINT_MIX[ENDPOINT_MIX.length - 1];
}

function computePlateauBoundaries(stages) {
    const boundaries = [];
    let cursorMs = 0;
    let previousTarget = 0;
    let plateauCount = 0;

    for (const stage of stages) {
        const durationMs = parseDurationToMs(stage.duration);
        const startMs = cursorMs;
        const endMs = cursorMs + (Number.isFinite(durationMs) ? durationMs : 0);

        if (stage.target === previousTarget) {
            plateauCount += 1;
            boundaries.push({ stage: `plateau-${plateauCount}`, startMs, endMs });
        }

        previousTarget = stage.target;
        cursorMs = endMs;
    }

    return boundaries;
}

function findCurrentPlateau(boundaries, elapsedMs) {
    for (const boundary of boundaries) {
        if (elapsedMs >= boundary.startMs && elapsedMs < boundary.endMs) {
            return boundary.stage;
        }
    }
    return null;
}

export const options = {
    scenarios: {
        ramp_up: {
            executor: 'ramping-vus',
            stages: STAGES,
        },
    },
    thresholds: {
        'http_req_duration{stage:plateau-1}': [`p(95)<${RAMP_P95_THRESHOLD_MS}`],
        'http_req_duration{stage:plateau-2}': [`p(95)<${RAMP_P95_THRESHOLD_MS}`],
        'http_req_duration{stage:plateau-3}': [`p(95)<${RAMP_P95_THRESHOLD_MS}`],
        'errors{stage:plateau-1}': [`rate<${RAMP_ERROR_THRESHOLD}`],
        'errors{stage:plateau-2}': [`rate<${RAMP_ERROR_THRESHOLD}`],
        'errors{stage:plateau-3}': [`rate<${RAMP_ERROR_THRESHOLD}`],
    },
};

export function setup() {
    return { plateauBoundaries: computePlateauBoundaries(STAGES) };
}

export default function (setupData) {
    const currentPlateau = findCurrentPlateau(setupData.plateauBoundaries, exec.instance.currentTestRunDuration);

    if (currentPlateau) {
        exec.vu.metrics.tags.stage = currentPlateau;
    } else {
        delete exec.vu.metrics.tags.stage;
    }

    const user = fixtures.users[(__VU - 1) % fixtures.users.length];
    const endpoint = pickWeightedEndpoint();
    const res = endpoint.request(user);

    errors.add(res.status !== 200, currentPlateau ? { stage: currentPlateau } : {});
}

export function handleSummary(data) {
    return writeScenarioSummary(data, 'ramp-up');
}
