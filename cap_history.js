(function () {
  const API_URL = "api/cap_history.php";
  const state = {
    data: null,
    search: "",
    rank: "all",
  };

  function el(id) {
    return document.getElementById(id);
  }

  function escapeHtml(value) {
    return String(value ?? "")
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");
  }

  function number(value) {
    const n = Number(value || 0);
    return Number.isFinite(n) ? n.toLocaleString() : "0";
  }


  function compactNumber(value) {
    const n = Number(value || 0);
    if (!Number.isFinite(n)) return "0";
    try {
      return new Intl.NumberFormat("en-AU", {
        notation: "compact",
        maximumFractionDigits: n >= 1000 ? 1 : 0,
      }).format(n);
    } catch {
      return number(n);
    }
  }

  function renderCapsPerDayChart() {
    const mount = el("capHistoryCapsChart");
    const data = state.data;
    if (!mount) return;

    const rows = Array.isArray(data?.caps_per_day_90d) ? data.caps_per_day_90d : [];
    if (!rows.length) {
      mount.innerHTML = '<section class="panel capHistoryChartPanel"><div class="capHistoryChartEmpty">No cap/day history is available yet.</div></section>';
      return;
    }

    const total = rows.reduce((sum, row) => sum + Number(row.count || 0), 0);
    const peak = Math.max(...rows.map(row => Number(row.count || 0)), 0);
    const maxY = Math.max(peak, 1);
    const width = 900;
    const height = 250;
    const padLeft = 48;
    const padRight = 18;
    const padTop = 22;
    const padBottom = 38;
    const plotW = width - padLeft - padRight;
    const plotH = height - padTop - padBottom;
    const bottomY = padTop + plotH;
    const gap = 2;
    const step = plotW / Math.max(rows.length, 1);
    const barW = Math.max(3, step - gap);
    const midY = padTop + plotH / 2;

    const bars = rows.map((row, index) => {
      const count = Math.max(0, Number(row.count || 0));
      const h = count > 0 ? Math.max(2, (count / maxY) * plotH) : 0;
      const x = padLeft + (index * step) + (gap / 2);
      const y = bottomY - h;
      const showLabel = index === 0 || index === rows.length - 1 || index % 15 === 0;
      const title = `${row.label || row.date || "Day"}: ${number(count)} caps`;
      return `
        <rect class="capHistoryChartBar" x="${x.toFixed(1)}" y="${y.toFixed(1)}" width="${barW.toFixed(1)}" height="${h.toFixed(1)}" rx="2">
          <title>${escapeHtml(title)}</title>
        </rect>
        ${showLabel ? `<text class="capHistoryChartAxis" x="${(x + barW / 2).toFixed(1)}" y="${height - 10}" text-anchor="middle">${escapeHtml(row.label || "")}</text>` : ""}
      `;
    }).join("");

    mount.innerHTML = `
      <section class="panel capHistoryChartPanel" aria-label="Caps per day for the last 90 days">
        <div class="capHistoryChartHeader">
          <div>
            <h2 class="h2">Caps/day — last 90 days</h2>
            <div class="muted">${number(total)} caps logged • Peak ${number(peak)} in one day</div>
          </div>
        </div>
        <div class="capHistoryChartScroll">
          <svg class="capHistoryCapsChart" viewBox="0 0 ${width} ${height}" role="img" aria-label="Caps per day for the last 90 days">
            <line class="capHistoryChartGrid" x1="${padLeft}" x2="${width - padRight}" y1="${padTop}" y2="${padTop}" />
            <line class="capHistoryChartGrid" x1="${padLeft}" x2="${width - padRight}" y1="${midY}" y2="${midY}" />
            <line class="capHistoryChartGrid" x1="${padLeft}" x2="${width - padRight}" y1="${bottomY}" y2="${bottomY}" />
            <text class="capHistoryChartAxis" x="${padLeft - 8}" y="${padTop + 4}" text-anchor="end">${escapeHtml(compactNumber(maxY))}</text>
            <text class="capHistoryChartAxis" x="${padLeft - 8}" y="${bottomY + 4}" text-anchor="end">0</text>
            ${bars}
          </svg>
        </div>
      </section>
    `;
  }

  function setText(id, value) {
    const node = el(id);
    if (node) node.textContent = value;
  }

  function configuredClanId() {
    return String(window.TRACKER_CONFIG?.clanId || "").trim();
  }

  function playerUrl(rsn) {
    const url = new URL("index", window.location.href);
    url.searchParams.set("player", String(rsn || ""));
    return url.toString();
  }

  function formatDate(value, timezone) {
    const v = String(value || "").trim();
    if (!v) return "—";
    return `${v}${timezone ? ` ${timezone}` : ""}`;
  }

  function buildFilteredGroups() {
    const data = state.data;
    if (!data || !Array.isArray(data.rank_groups)) return [];

    const needle = state.search.trim().toLowerCase();
    const wantedRank = state.rank;

    return data.rank_groups
      .filter(group => wantedRank === "all" || String(group.rank_name || "") === wantedRank)
      .map(group => {
        const members = (group.members || []).filter(member => {
          if (!needle) return true;
          return String(member.rsn || "").toLowerCase().includes(needle)
            || String(member.rank_name || "").toLowerCase().includes(needle);
        });

        const capCount = members.reduce((sum, m) => sum + Number(m.cap_count || 0), 0);
        const visitCount = members.reduce((sum, m) => sum + Number(m.visit_count || 0), 0);

        return {
          ...group,
          members,
          member_count: members.length,
          cap_count: capCount,
          visit_count: visitCount,
        };
      })
      .filter(group => group.members.length > 0);
  }

  function renderRankFilter() {
    const select = el("capHistoryRankFilter");
    const data = state.data;
    if (!select || !data || !Array.isArray(data.rank_groups)) return;

    const current = state.rank;
    select.innerHTML = '<option value="all">All ranks</option>' + data.rank_groups.map(group => {
      const rank = String(group.rank_name || "Unranked");
      const selected = rank === current ? " selected" : "";
      return `<option value="${escapeHtml(rank)}"${selected}>${escapeHtml(rank)}</option>`;
    }).join("");
  }

  function render() {
    const data = state.data;
    const container = el("capHistoryGroups");
    if (!container) return;

    if (!data) {
      container.innerHTML = "";
      const chart = el("capHistoryCapsChart");
      if (chart) chart.innerHTML = "";
      return;
    }

    const timezone = data.clan?.timezone || "UTC";
    setText("capHistorySubheading", `${data.clan?.name || "Clan"} active clan member citadel history by rank.`);
    setText("capHistoryActiveMembers", number(data.stats?.active_members));
    setText("capHistoryTotalVisits", number(data.stats?.total_visits));
    setText("capHistoryTotalCaps", number(data.stats?.total_caps));
    setText("capHistoryGenerated", data.generated_at_utc ? `Generated: ${data.generated_at_utc} UTC` : "");
    renderCapsPerDayChart();

    renderRankFilter();

    const groups = buildFilteredGroups();
    const totalShown = groups.reduce((sum, group) => sum + group.members.length, 0);
    setText("capHistoryStatus", `${number(totalShown)} members shown`);

    if (!groups.length) {
      container.innerHTML = '<div class="panel"><div class="muted">No active members matched this filter.</div></div>';
      return;
    }

    container.innerHTML = groups.map(group => {
      const icon = group.rank_icon
        ? `<img class="capHistoryRankIcon" src="${escapeHtml(group.rank_icon)}" alt="" loading="lazy" onerror="this.remove()" />`
        : "";

      const rows = group.members.map(member => {
        const privatePill = member.is_private ? '<span class="pill private">Private</span>' : "";
        return `
          <tr class="capHistoryMemberRow" data-rsn="${escapeHtml(member.rsn)}">
            <td class="capHistoryNameCell">
              <a href="${escapeHtml(playerUrl(member.rsn))}" class="leaderLink">${escapeHtml(member.rsn)}</a>
              ${privatePill}
            </td>
            <td class="capHistoryCountCell">${number(member.visit_count)}</td>
            <td class="capHistoryCountCell">${number(member.cap_count)}</td>
            <td>${escapeHtml(formatDate(member.last_visited_at_local, timezone))}</td>
            <td>${escapeHtml(formatDate(member.last_capped_at_local, timezone))}</td>
          </tr>
        `;
      }).join("");

      return `
        <section class="panel capHistoryGroup">
          <div class="capHistoryGroupHeader">
            <div class="capHistoryRankTitle">
              ${icon}
              <div>
                <h2 class="h2">${escapeHtml(group.rank_name || "Unranked")}</h2>
                <div class="muted">${number(group.member_count)} members</div>
              </div>
            </div>
            <div class="capHistoryGroupStats" aria-label="${escapeHtml(group.rank_name || "Rank")} totals">
              <span><strong>${number(group.visit_count)}</strong> visits</span>
              <span><strong>${number(group.cap_count)}</strong> caps</span>
            </div>
          </div>

          <div class="capHistoryTableWrap">
            <table class="capHistoryTable">
              <thead>
                <tr>
                  <th>Member</th>
                  <th>Visits</th>
                  <th>Caps</th>
                  <th>Last Visit</th>
                  <th>Last Cap</th>
                </tr>
              </thead>
              <tbody>${rows}</tbody>
            </table>
          </div>
        </section>
      `;
    }).join("");
  }

  async function loadCapHistory() {
    const status = el("capHistoryStatus");
    if (status) status.textContent = "Loading cap history…";

    try {
      const params = new URLSearchParams();
      const clanId = configuredClanId();
      if (clanId) params.set("clan", clanId);

      const response = await fetch(`${API_URL}${params.toString() ? `?${params.toString()}` : ""}`, {
        headers: { "Accept": "application/json" },
      });

      const data = await response.json();
      if (!response.ok || !data.ok) {
        throw new Error(data.error || `Unable to load cap history (${response.status})`);
      }

      state.data = data;
      render();
    } catch (error) {
      if (status) status.textContent = error?.message || "Unable to load cap history.";
      const container = el("capHistoryGroups");
      if (container) container.innerHTML = "";
      const chart = el("capHistoryCapsChart");
      if (chart) chart.innerHTML = "";
    }
  }

  function wireCapHistory() {
    if (!el("capHistoryPage")) return;

    el("capHistorySearch")?.addEventListener("input", event => {
      state.search = String(event.target.value || "");
      render();
    });

    el("capHistoryRankFilter")?.addEventListener("change", event => {
      state.rank = String(event.target.value || "all");
      render();
    });

    loadCapHistory();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", wireCapHistory);
  } else {
    wireCapHistory();
  }
})();
