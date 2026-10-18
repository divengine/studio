/* OWL templates of the schema evolution page (QWeb XML). */
export default `<templates xml:space="preserve">
    <t t-name="divengine_database.SchemaEvolution">
        <div class="dv_ev">
            <!-- ============================== sections ============================== -->
            <div class="dv_ev_nav">
                <span class="dv_ev_brand">◈ SCHEMA EVOLUTION</span>
                <button t-att-class="state.section === 'diff' ? 'is-on' : ''" t-on-click="() => this.setSection('diff')">Diff</button>
                <button t-att-class="state.section === 'history' ? 'is-on' : ''" t-on-click="() => this.setSection('history')">History <small>future</small></button>
                <button t-att-class="state.section === 'drift' ? 'is-on' : ''" t-on-click="() => this.setSection('drift')">Drift <small>future</small></button>
            </div>

            <div t-if="state.section === 'history'" class="dv_ev_future">
                <h3>Object history</h3>
                <p>Planned: the timeline of one table / column / relation across every snapshot of a database, built
                   from the same diff engine (a chain of pairwise diffs keyed by canonical identity). Not implemented yet.</p>
            </div>
            <div t-elif="state.section === 'drift'" class="dv_ev_future">
                <h3>Drift detection</h3>
                <p>Planned: compare an environment's snapshot against a reference (e.g. local vs production, or the
                   migration head) and report unexpected differences. Not implemented yet.</p>
            </div>

            <t t-else="">
                <!-- ============================== pair selector ============================== -->
                <div class="dv_ev_pair">
                    <label>FROM
                        <select t-on-change="setFrom">
                            <option value="">— choose —</option>
                            <t t-foreach="state.snapshotGroups" t-as="g" t-key="g.code">
                                <optgroup t-att-label="g.database">
                                    <t t-foreach="g.snapshots" t-as="sn" t-key="sn.id">
                                        <option t-att-value="sn.id" t-att-selected="sn.id === state.fromId" t-esc="snapshotLabel(sn)"/>
                                    </t>
                                </optgroup>
                            </t>
                        </select>
                    </label>
                    <button class="dv_ev_swap" title="Swap FROM and TO" t-on-click="() => this.swap()">⇄</button>
                    <label>TO
                        <select t-on-change="setTo">
                            <option value="">— choose —</option>
                            <t t-foreach="state.snapshotGroups" t-as="g" t-key="g.code">
                                <optgroup t-att-label="g.database">
                                    <t t-foreach="g.snapshots" t-as="sn" t-key="sn.id">
                                        <option t-att-value="sn.id" t-att-selected="sn.id === state.toId" t-esc="snapshotLabel(sn)"/>
                                    </t>
                                </optgroup>
                            </t>
                        </select>
                    </label>
                    <button class="is-primary" t-att-disabled="!canCompare or state.phase === 'loading'" t-on-click="() => this.compare()">Compare</button>
                    <button t-att-disabled="!hasPrevious or state.phase === 'loading'" t-on-click="() => this.compareWithPrevious()"
                            title="FROM := the previous ready snapshot of TO's database">Compare with previous</button>
                    <span class="dv_ev_spacer"/>
                    <button t-att-disabled="!state.diff" t-on-click="() => this.openVisualDiff()">◈ Open visual diff</button>
                    <button t-att-disabled="!state.diff" t-on-click="() => this.exportJson()">Export JSON</button>
                    <button t-att-disabled="!state.diff" t-on-click="() => this.exportMarkdown()">Export Markdown</button>
                </div>

                <div t-if="state.phase === 'loading'" class="dv_ev_msg">Computing diff…</div>
                <div t-if="state.phase === 'error'" class="dv_ev_msg is-error" t-esc="state.error"/>
                <div t-if="state.phase === 'idle' and !state.diff" class="dv_ev_msg">
                    Choose FROM and TO snapshots and press Compare. The diff is computed from the snapshots on request and never stored.
                </div>

                <t t-if="state.diff">
                    <t t-set="d" t-value="state.diff"/>
                    <!-- ============================== summary ============================== -->
                    <div class="dv_ev_summary">
                        <div class="dv_ev_card dv_ev_card_head">
                            <div><small>FROM</small> <b t-esc="d.from.key"/> <small t-esc="d.from.captured_at"/></div>
                            <div><small>TO</small> <b t-esc="d.to.key"/> <small t-esc="d.to.captured_at"/></div>
                            <div class="dv_ev_totals">
                                <span><b t-esc="fmt(d.summary.changed)"/> changed</span>
                                <span><t t-esc="fmt(d.summary.unchanged)"/> unchanged</span>
                                <span><t t-esc="fmt(d.summary.compared)"/> compared</span>
                                <span t-if="d.stats"><t t-esc="d.stats.ms"/> ms</span>
                            </div>
                        </div>
                        <table class="dv_ev_card dv_ev_counts">
                            <thead><tr><th/><th class="is-added">+ added</th><th class="is-removed">− removed</th><th class="is-modified">~ modified</th><th>= unchanged</th></tr></thead>
                            <tbody>
                                <tr t-foreach="summaryRows" t-as="r" t-key="r.type">
                                    <th t-esc="r.label"/>
                                    <td t-att-class="r.added ? 'is-added' : ''" t-esc="r.added"/>
                                    <td t-att-class="r.removed ? 'is-removed' : ''" t-esc="r.removed"/>
                                    <td t-att-class="r.modified ? 'is-modified' : ''" t-esc="r.modified"/>
                                    <td t-esc="r.unchanged"/>
                                </tr>
                            </tbody>
                        </table>
                        <div class="dv_ev_card dv_ev_sev" title="Deterministic technical classification — not business impact">
                            <small>TECHNICAL IMPACT</small>
                            <div t-foreach="severityRows" t-as="sv" t-key="sv.key" t-att-class="'dv_ev_sev_row is-' + sv.key">
                                <span t-esc="sv.label"/><b t-esc="sv.count"/>
                            </div>
                            <div t-if="!severityRows.length" class="dv_ev_muted">no changes</div>
                        </div>
                    </div>
                    <!-- notes come from the server; with a cross-database pair the first note is that warning -->
                    <div t-foreach="d.notes" t-as="n" t-key="n_index"
                         t-att-class="'dv_ev_msg ' + (d.cross_database and n_index === 0 ? 'is-warn' : 'is-note')" t-esc="n"/>

                    <!-- ============================== tabs ============================== -->
                    <div class="dv_ev_tabs">
                        <button t-att-class="state.tab === 'changes' ? 'is-on' : ''" t-on-click="() => this.state.tab = 'changes'">Changes</button>
                        <button t-att-class="state.tab === 'report' ? 'is-on' : ''" t-on-click="() => this.state.tab = 'report'">Report</button>
                    </div>

                    <t t-if="state.tab === 'changes'">
                        <div class="dv_ev_filters">
                            <t t-foreach="['all', 'added', 'removed', 'modified']" t-as="c" t-key="c">
                                <button t-att-class="(state.filters.change === c ? 'is-on ' : '') + 'is-' + c"
                                        t-on-click="() => this.setChangeFilter(c)" t-esc="c.toUpperCase()"/>
                            </t>
                            <span class="dv_ev_sep"/>
                            <t t-foreach="groupKeys" t-as="g" t-key="g">
                                <button t-att-class="state.filters.groups[g] ? 'is-on' : ''" t-on-click="() => this.toggleGroup(g)"
                                        t-esc="groupLabel[g]"/>
                            </t>
                            <span class="dv_ev_sep"/>
                            <button t-att-class="state.includeUnchanged ? 'is-on' : ''" t-on-click="() => this.toggleUnchanged()"
                                    title="Include unchanged objects (refetches the diff)">Include unchanged</button>
                            <input type="search" placeholder="Search table / object…" t-on-input="onQuery" t-att-value="state.filters.query"/>
                            <button t-on-click="() => this.expandAll(true)" title="Expand all">⊞</button>
                            <button t-on-click="() => this.expandAll(false)" title="Collapse all">⊟</button>
                        </div>

                        <div class="dv_ev_split">
                            <!-- change tree -->
                            <div class="dv_ev_tree">
                                <t t-set="tr" t-value="tree"/>
                                <div t-if="!tr.length" class="dv_ev_muted">No objects match the filters.</div>
                                <div t-foreach="tr" t-as="g" t-key="g.key" class="dv_ev_node">
                                    <div t-att-class="'dv_ev_group is-' + g.change + (g.tableObject and state.selectedKey === g.tableObject.key ? ' is-selected' : '')"
                                         t-on-click="() => g.tableObject ? this.select(g.tableObject) : this.toggleOpen(g.key)">
                                        <span class="dv_ev_twisty" t-on-click.stop="() => this.toggleOpen(g.key)"
                                              t-esc="g.children.length ? (state.open[g.key] ? '▾' : '▸') : '·'"/>
                                        <span class="dv_ev_sign" t-esc="sign(g.change)"/>
                                        <span class="dv_ev_name" t-esc="g.table"/>
                                        <span t-if="g.children.length" class="dv_ev_badge" t-esc="g.children.length"/>
                                    </div>
                                    <t t-if="state.open[g.key]">
                                        <div t-foreach="g.children" t-as="o" t-key="o.key"
                                             t-att-class="'dv_ev_leaf is-' + o.change + (state.selectedKey === o.key ? ' is-selected' : '')"
                                             t-on-click="() => this.select(o)">
                                            <span class="dv_ev_sign" t-esc="sign(o.change)"/>
                                            <span class="dv_ev_type" t-esc="typeLabel[o.type]"/>
                                            <span class="dv_ev_name" t-esc="label(o)"/>
                                            <span t-if="o.severity" t-att-class="'dv_ev_sevtag is-' + o.severity" t-esc="severityLabel[o.severity]"/>
                                        </div>
                                    </t>
                                </div>
                            </div>

                            <!-- detail panel -->
                            <div class="dv_ev_detail">
                                <t t-set="sel" t-value="selected"/>
                                <div t-if="!sel" class="dv_ev_muted">Select an object to see BEFORE / AFTER.</div>
                                <t t-else="">
                                    <div class="dv_ev_detail_head">
                                        <span t-att-class="'dv_ev_chip is-' + sel.change" t-esc="sign(sel.change) + ' ' + sel.change.toUpperCase()"/>
                                        <span class="dv_ev_type" t-esc="typeLabel[sel.type]"/>
                                        <span t-if="sel.severity" t-att-class="'dv_ev_sevtag is-' + sel.severity" t-esc="severityLabel[sel.severity]"/>
                                        <h3 t-esc="label(sel)"/>
                                        <code class="dv_ev_key" t-esc="sel.key"/>
                                    </div>
                                    <table class="dv_ev_props">
                                        <thead><tr><th>Property</th><th>BEFORE <small>(FROM)</small></th><th>AFTER <small>(TO)</small></th></tr></thead>
                                        <tbody>
                                            <tr t-foreach="rows(sel)" t-as="r" t-key="r.property" t-att-class="r.changed ? 'is-changed' : ''">
                                                <th t-esc="r.property"/>
                                                <td t-esc="r.before"/>
                                                <td t-esc="r.after"/>
                                            </tr>
                                        </tbody>
                                    </table>
                                    <div class="dv_ev_detail_actions">
                                        <button t-if="recordUrl(sel, 'from')"
                                                t-on-click="() => this.openRecord(sel, 'from')">Open in FROM</button>
                                        <button t-if="recordUrl(sel, 'to')"
                                                t-on-click="() => this.openRecord(sel, 'to')">Open in TO</button>
                                    </div>
                                </t>
                            </div>
                        </div>
                    </t>

                    <!-- ============================== report ============================== -->
                    <div t-if="state.tab === 'report'" class="dv_ev_report">
                        <t t-set="rep" t-value="report"/>
                        <h2 t-esc="rep.title"/>
                        <p><b>From</b> <t t-esc="rep.from.key"/> · <b>To</b> <t t-esc="rep.to.key"/></p>
                        <t t-if="rep.risk.length">
                            <h3>Technical impact <small>(deterministic, not business impact)</small></h3>
                            <div t-foreach="rep.risk" t-as="rg" t-key="rg.severity">
                                <h4 t-att-class="'is-' + rg.severity"><t t-esc="rg.label"/> (<t t-esc="rg.items.length"/>)</h4>
                                <ul><li t-foreach="rg.items" t-as="it" t-key="it_index" t-esc="it"/></ul>
                            </div>
                        </t>
                        <div t-foreach="rep.sections" t-as="sec" t-key="sec.title">
                            <h3><t t-esc="sec.title"/> (<t t-esc="sec.items.length"/>)</h3>
                            <ul><li t-foreach="sec.items" t-as="it" t-key="it_index" t-esc="it"/></ul>
                        </div>
                        <p t-if="!rep.sections.length" class="dv_ev_muted">No differences.</p>
                        <p t-if="rep.notCompared.length" class="dv_ev_muted">
                            Not captured by the snapshot collector, therefore not compared: <t t-esc="rep.notCompared.join(', ')"/>.
                        </p>
                    </div>
                </t>
            </t>
        </div>
    </t>
</templates>
`;
