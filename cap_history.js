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
      return;
    }

    const timezone = data.clan?.timezone || "UTC";
    setText("capHistorySubheading", `${data.clan?.name || "Clan"} active clan member citadel history by rank.`);
    setText("capHistoryActiveMembers", number(data.stats?.active_members));
    setText("capHistoryTotalVisits", number(data.stats?.total_visits));
    setText("capHistoryTotalCaps", number(data.stats?.total_caps));
    setText("capHistoryGenerated", data.generated_at_utc ? `Generated: ${data.generated_at_utc} UTC` : "");

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
