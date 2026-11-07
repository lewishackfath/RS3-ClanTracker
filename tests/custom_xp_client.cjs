const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../app.js'), 'utf8');
const functions = ['todayInTimezone', 'customXpRangeError', 'syncCustomXpRange', 'validateCustomXpRange',
  'xpPeriodParams', 'buildClanApiUrl', 'buildPlayerApiUrl', 'fetchClanTopEarnersForSkill', 'refreshPlayerInBackground'];
const extracted = functions.map(name => {
  const match = source.match(new RegExp(`^(?:async )?function ${name}\\([^]*?^}`, 'm'));
  assert.ok(match, name);
  return match[0];
});
const elements = new Map();
const qs = id => {
  if (!elements.has(id)) {
    const classes = new Set();
    elements.set(id, { value: '', textContent: '', attributes: {},
      classList: { toggle: (name, enabled) => enabled ? classes.add(name) : classes.delete(name), contains: name => classes.has(name) },
      setAttribute(name, value) { this.attributes[name] = value; },
    });
  }
  return elements.get(id);
};
const scope = { Date, Intl, URLSearchParams, qs, API: { clanOverview: '/clan', player: '/player' },
  normaliseActivityLimit: value => Number(value), getParams: () => ({ clan: '1', player: 'Lodo' }),
  playerNeedsBackgroundRefresh: () => true, playerXpNeedsRefresh: () => false,
  populateXpPeriods: () => {}, populateActivityLimitOptions: () => {}, renderPlayer: () => {},
};
vm.createContext(scope);
vm.runInContext(`
  const xpCustomRanges = {clan: {start: '', end: '', timezone: 'UTC'}, player: {start: '', end: '', timezone: 'UTC'}};
  let selectedClanXpPeriod = 'custom', selectedXpPeriod = 'custom', selectedActivityLimit = 20;
  let playerData = {ok: true}, clanLoadVersion = 0, playerLoadVersion = 0;
  const clanSkillTopCache = new Map(), playerBackgroundRefreshInFlight = new Set();
  ${extracted.join('\n')}
`, scope);
const run = code => vm.runInContext(code, scope);
const params = url => new URL(url, 'https://example.test').searchParams;

(async () => {
  assert.equal(scope.todayInTimezone('Australia/Sydney', new Date('2026-10-04T14:00:00Z')), '2026-10-05');
  assert.equal(scope.todayInTimezone('America/Los_Angeles', new Date('2026-10-04T01:00:00Z')), '2026-10-03');
  scope.syncCustomXpRange('clan', '7d', 'Australia/Sydney');
  assert.equal(qs('clanXpRange').classList.contains('hidden'), true);
  scope.syncCustomXpRange('clan', 'custom');
  assert.equal(qs('clanXpRange').classList.contains('hidden'), false);
  assert.equal(qs('clanXpStart').value, scope.todayInTimezone('Australia/Sydney'));
  assert.equal(qs('clanXpEnd').value, qs('clanXpStart').value);
  run("xpCustomRanges.clan.start = '2026-10-04'; xpCustomRanges.clan.end = '2026-10-05';");
  scope.syncCustomXpRange('clan', '7d');
  scope.syncCustomXpRange('clan', 'custom');
  assert.equal(qs('clanXpStart').value, '2026-10-04', 'Retain dates across presets/refreshes');
  assert.equal(scope.customXpRangeError({start: '2024-02-29', end: '2024-02-29'}), '');
  for (const range of [{start: '', end: ''}, {start: '2026-02-29', end: '2026-03-01'}, {start: '2026-10-05', end: '2026-10-04'}]) {
    assert.ok(scope.customXpRangeError(range));
  }
  run("xpCustomRanges.player = {...xpCustomRanges.clan};");
  for (const url of [scope.buildClanApiUrl(1, 'custom', 'attack'), scope.buildPlayerApiUrl('Lodo', 'custom', 20, false)]) {
    assert.equal(params(url).get('start_date'), '2026-10-04');
    assert.equal(params(url).get('end_date'), '2026-10-05');
  }
  assert.equal(params(scope.buildPlayerApiUrl('Lodo', '7d', 20)).has('start_date'), false);
  assert.equal(params(scope.buildClanApiUrl(1, '30d')).has('end_date'), false);

  const requests = [];
  scope.fetchJson = async url => { requests.push(url); return {ok: true, top_earners: [{rsn: 'Lodo', gained_xp: requests.length}]}; };
  await scope.fetchClanTopEarnersForSkill('attack');
  await scope.fetchClanTopEarnersForSkill('attack');
  assert.equal(requests.length, 1, 'Reuse the same range');
  run("xpCustomRanges.clan.end = '2026-10-06';");
  await scope.fetchClanTopEarnersForSkill('attack');
  assert.equal(requests.length, 2, 'A new range has its own leaderboard cache');

  let resolve;
  scope.fetchJson = url => { requests.push(url); return new Promise(done => { resolve = done; }); };
  const pending = scope.refreshPlayerInBackground('Lodo', 'custom');
  run("xpCustomRanges.player.end = '2026-10-07';");
  resolve({ok: true, staleResponse: true});
  await pending;
  assert.equal(run('playerData.staleResponse'), undefined, 'Discard background responses for old dates');
  assert.equal(run('playerBackgroundRefreshInFlight.size'), 0);
  run("xpCustomRanges.player.start = '2026-10-08';");
  assert.equal(scope.validateCustomXpRange('player', 'custom'), false);
  assert.match(qs('playerXpRangeError').textContent, /End date/);
  console.log('PASS: custom date defaults, validation, requests, leaderboard caching and stale refresh protection');
})().catch(error => { console.error(error); process.exitCode = 1; });
