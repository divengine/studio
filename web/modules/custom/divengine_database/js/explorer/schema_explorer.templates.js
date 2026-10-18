/* OWL templates of the schema explorer page (QWeb XML). */
export default `<templates xml:space="preserve">
    <t t-name="divengine_database.SchemaExplorer">
        <div class="dv_sx" t-ref="root"
             t-att-class="'dv_sx_bg_' + state.gfx.background + (state.fullscreen ? ' dv_sx_fullscreen' : '') + (state.diff ? ' dv_sx_is_diff' : '')">

            <!-- ============================== command bar ============================== -->
            <div class="dv_sx_bar">
                <div class="dv_sx_brand" t-att-title="state.snapshotLabel">
                    <span class="dv_sx_glyph">◈</span>
                    <span class="dv_sx_brand_text">SCHEMA</span>
                    <t t-if="state.diff">
                        <span class="dv_sx_diff_pair" t-att-title="'Schema diff FROM ' + state.diff.fromLabel + ' TO ' + state.diff.toLabel">
                            DIFF <b t-esc="state.diff.fromLabel"/> → <b t-esc="state.diff.toLabel"/>
                        </span>
                        <button class="dv_sx_diff_back" t-on-click="() => this.openEvolution()"
                                title="Back to the Schema Evolution change list">☰ Changes</button>
                    </t>
                    <select t-else="" class="dv_sx_select dv_sx_snapshot_select" title="Database / snapshot"
                            t-on-change="switchSnapshot" t-att-disabled="state.switching">
                        <t t-foreach="state.snapshotGroups" t-as="g" t-key="g.database">
                            <optgroup t-att-label="g.database">
                                <t t-foreach="g.snapshots" t-as="sn" t-key="sn.id">
                                    <option t-att-value="sn.id" t-att-selected="sn.id === state.snapshotId" t-esc="sn.label"/>
                                </t>
                            </optgroup>
                        </t>
                    </select>
                </div>

                <div class="dv_sx_search">
                    <input type="search" class="dv_sx_input" t-ref="search"
                           placeholder="Search table, schema or column  ( / )"
                           t-on-input="onSearchInput" t-on-keydown="onSearchKeydown"/>
                    <span t-if="state.searching" class="dv_sx_spin"/>
                    <ul t-if="state.results.length" class="dv_sx_results">
                        <li t-foreach="state.results" t-as="r" t-key="r.key"
                            t-on-click="() => this.pickResult(r)">
                            <span class="dv_sx_kind" t-att-data-kind="r.kind" t-esc="r.kind"/>
                            <span class="dv_sx_rtext" t-esc="r.text"/>
                        </li>
                    </ul>
                </div>

                <div t-if="state.diff" class="dv_sx_group dv_sx_diff_focus" title="Diff focus: which part of the union FROM ∪ TO is laid out">
                    <span class="dv_sx_label">FOCUS</span>
                    <button t-att-class="state.diffFocus === 'changed' ? 'is-on' : ''" t-on-click="() => this.setDiffFocus('changed')"
                            title="Only tables that changed">Changed</button>
                    <button t-att-class="state.diffFocus === 'neighbors' ? 'is-on' : ''" t-on-click="() => this.setDiffFocus('neighbors')"
                            title="Changed tables and their FK neighbours (context)">+ Neighbors</button>
                    <button t-att-class="state.diffFocus === 'all' ? 'is-on' : ''" t-on-click="() => this.setDiffFocus('all')"
                            title="Whole union, unchanged tables subdued">All</button>
                    <span class="dv_sx_diff_count" t-esc="fmt(state.diff.changedTables) + ' changed'"/>
                </div>
                <div t-else="" class="dv_sx_group" title="What to show">
                    <button t-att-class="state.scope === 'neighborhood' ? 'is-on' : ''"
                            t-on-click="() => this.showNeighborhood()">Neighborhood</button>
                    <button t-att-class="state.scope === 'all' ? 'is-on' : ''"
                            t-on-click="() => this.requestShowAll()">Show all</button>
                </div>

                <div class="dv_sx_group" title="FK neighborhood depth" t-att-class="{ 'is-muted': state.scope === 'all' }">
                    <span class="dv_sx_label">DEPTH</span>
                    <t t-foreach="[1, 2, 3]" t-as="d" t-key="d">
                        <button t-att-class="state.depth === d ? 'is-on' : ''" t-on-click="() => this.setDepth(d)" t-esc="d"/>
                    </t>
                </div>

                <div class="dv_sx_group" title="Follow relations: both ways, outgoing (tables this one references), incoming (tables referencing this one)"
                     t-att-class="{ 'is-muted': state.scope === 'all' }">
                    <button t-att-class="state.direction === 'both' ? 'is-on' : ''" t-on-click="() => this.setDirection('both')">⇄</button>
                    <button t-att-class="state.direction === 'outgoing' ? 'is-on' : ''" t-on-click="() => this.setDirection('outgoing')">OUT →</button>
                    <button t-att-class="state.direction === 'incoming' ? 'is-on' : ''" t-on-click="() => this.setDirection('incoming')">← IN</button>
                </div>

                <div class="dv_sx_group" title="Relationship kind">
                    <button t-att-class="state.kinds === 'real' ? 'is-on' : ''" t-on-click="() => this.setKinds('real')">Real FK</button>
                    <button t-att-class="state.kinds === 'implied' ? 'is-on' : ''" t-on-click="() => this.setKinds('implied')">Implied</button>
                    <button t-att-class="state.kinds === 'both' ? 'is-on' : ''" t-on-click="() => this.setKinds('both')">Both</button>
                </div>

                <div class="dv_sx_group" title="Table detail (layout density). Auto adapts to how many tables are visible.">
                    <span class="dv_sx_label">DETAIL</span>
                    <t t-foreach="['auto', 'compact', 'structure', 'full']" t-as="dn" t-key="dn">
                        <button t-att-class="state.density === dn ? 'is-on' : ''" t-on-click="() => this.setDensity(dn)" t-esc="dn"/>
                    </t>
                </div>

                <div class="dv_sx_group dv_sx_filters" title="Filters">
                    <select class="dv_sx_select" t-on-change="setSchema" t-att-value="state.schema">
                        <option value="">All schemas</option>
                        <t t-foreach="state.schemas" t-as="sc" t-key="sc">
                            <option t-att-value="sc" t-att-selected="state.schema === sc" t-esc="sc || '(none)'"/>
                        </t>
                    </select>
                    <button t-att-class="state.showTables ? 'is-on' : ''" t-on-click="() => this.toggleType('table')">Tables</button>
                    <button t-att-class="state.showViews ? 'is-on' : ''" t-on-click="() => this.toggleType('view')">Views</button>
                    <select class="dv_sx_select" t-on-change="(ev) => this.setConnectivity(ev.target.value)">
                        <option value="any" t-att-selected="state.connectivity === 'any'">Any connectivity</option>
                        <option value="connected" t-att-selected="state.connectivity === 'connected'">Connected only</option>
                        <option value="isolated" t-att-selected="state.connectivity === 'isolated'">Isolated only</option>
                    </select>
                </div>

                <div class="dv_sx_group" title="Layout: Hierarchical (FK layers, orthogonal routes), Organic (related tables cluster, straight lines), Radial (focus table in the centre, rings by FK distance). Tables without drawn relations are packed in a grid below.">
                    <span class="dv_sx_label">LAYOUT</span>
                    <select class="dv_sx_select" t-on-change="(ev) => this.setLayoutAlgorithm(ev.target.value)">
                        <t t-foreach="algorithms" t-as="al" t-key="al">
                            <option t-att-value="al" t-att-selected="state.layoutAlgorithm === al" t-esc="algorithmLabel(al)"/>
                        </t>
                    </select>
                    <button t-att-class="state.layoutDirection === 'RIGHT' ? 'is-on' : ''" t-att-disabled="state.layoutAlgorithm !== 'layered'"
                            t-on-click="() => this.setLayoutDirection('RIGHT')" title="Left to right (Hierarchical)">→</button>
                    <button t-att-class="state.layoutDirection === 'DOWN' ? 'is-on' : ''" t-att-disabled="state.layoutAlgorithm !== 'layered'"
                            t-on-click="() => this.setLayoutDirection('DOWN')" title="Top to bottom (Hierarchical)">↓</button>
                    <button t-on-click="() => this.relayout()" title="Re-run ELK layout">⟳</button>
                    <button t-on-click="() => this.resetPositions()" title="Forget manual positions for this view">⌖</button>
                </div>

                <div class="dv_sx_group" title="Hub collapsing: relations into a table referenced by at least N visible relations are summarised on each table instead of drawn (the exploration focus and expanded hubs are exempt)">
                    <span class="dv_sx_label">HUBS</span>
                    <t t-foreach="hubChoices" t-as="hc" t-key="hc">
                        <button t-att-class="state.hubThreshold === hc ? 'is-on' : ''" t-on-click="() => this.setHubThreshold(hc)"
                                t-esc="hc ? hc + '+' : 'off'"/>
                    </t>
                </div>

                <div class="dv_sx_group dv_sx_gfx"
                     title="Graphics quality — changes rendering cost and visual detail only, never the schema data">
                    <span class="dv_sx_label">GFX</span>
                    <select class="dv_sx_select" t-on-change="setGraphicsPreset">
                        <t t-foreach="['auto', 'low', 'balanced', 'high', 'ultra', 'custom']" t-as="gp" t-key="gp">
                            <option t-att-value="gp" t-att-selected="state.gfx.preset === gp" t-esc="levelLabel(gp)"/>
                        </t>
                    </select>
                    <span t-if="state.gfx.preset === 'auto'" class="dv_sx_gfx_level"
                          t-att-title="state.gfx.autoAction" t-esc="'→ ' + levelLabel(state.gfx.level)"/>
                    <button t-att-class="state.gfx.panel ? 'is-on' : ''" t-on-click="() => this.toggleGraphicsPanel()"
                            title="Custom graphics settings">⚙</button>
                </div>

                <div class="dv_sx_group" title="View">
                    <button t-on-click="() => this.zoomOut()">−</button>
                    <button t-on-click="() => this.fit()" title="Fit to screen (F)">FIT</button>
                    <button t-on-click="() => this.zoomIn()">+</button>
                    <button t-att-class="state.showMinimap ? 'is-on' : ''" t-on-click="() => this.toggleMinimap()" title="Overview map">▦</button>
                    <button t-att-class="state.fullscreen ? 'is-on' : ''" t-on-click="() => this.toggleFullscreen()" title="Full screen">⛶</button>
                    <button t-att-class="state.showPerf ? 'is-on' : ''" t-on-click="() => this.togglePerf()" title="Performance panel">ƒ</button>
                </div>
            </div>

            <!-- ============================== guidance banners ============================== -->
            <div t-if="state.confirmAll" class="dv_sx_banner is-warn">
                <span>
                    Show all <b t-esc="fmt(model.tableCount)"/> tables and <b t-esc="fmt(model.relationCount)"/> relations?
                    The whole snapshot is laid out at <b t-esc="estimateAllDensity()"/> detail (names only beyond 250 tables);
                    zoom in for more. Layout runs in the background.
                </span>
                <button class="is-on" t-on-click="() => this.confirmShowAll()">Show all</button>
                <button t-on-click="() => this.cancelShowAll()">Cancel</button>
            </div>
            <div t-elif="state.notice" class="dv_sx_banner">
                <span t-esc="state.notice.text"/>
                <button t-if="!state.diff" t-on-click="() => this.requestShowAll()">Show all</button>
                <button t-on-click="() => this.dismissNotice()">✕</button>
            </div>
            <div t-if="state.diff and state.diff.crossDatabase" class="dv_sx_banner is-warn">
                Cross-database comparison: identity is name-based — differences may reflect two different databases, not evolution.
            </div>
            <div t-if="state.layoutNotice" class="dv_sx_banner is-warn">
                <span t-esc="state.layoutNotice"/>
            </div>
            <div t-if="state.hud.truncated" class="dv_sx_banner is-warn">
                The neighborhood reached the safety bound and was truncated — lower the depth, filter, or pick a narrower table.
            </div>

            <!-- ============================== stage ============================== -->
            <div class="dv_sx_body">
                <div class="dv_sx_stage" t-ref="stage"/>

                <div t-if="state.phase === 'loading'" class="dv_sx_overlay">
                    <span class="dv_sx_pulse"/> Loading schema…
                </div>
                <div t-if="state.phase === 'layout'" class="dv_sx_overlay is-soft">
                    <span class="dv_sx_pulse"/> Computing layout (ELK)…
                </div>
                <div t-if="state.phase === 'error'" class="dv_sx_overlay is-error" t-esc="state.error"/>

                <!-- status HUD -->
                <div t-if="state.hud.visible !== undefined" class="dv_sx_hud">
                    <span>VISIBLE <b t-esc="fmt(state.hud.visible)"/> / <t t-esc="fmt(state.hud.total)"/> TABLES</span>
                    <span>RELATIONS <b t-esc="fmt(state.hud.visibleRelations)"/> / <t t-esc="fmt(state.hud.totalRelations)"/></span>
                    <span>LAYOUT <b t-esc="state.hud.layout"/></span>
                    <span>DETAIL <b t-esc="state.hud.detail"/> · <t t-esc="state.hud.density"/></span>
                    <span t-if="state.hud.filteredOut">FILTERED <b t-esc="fmt(state.hud.filteredOut)"/></span>
                    <span t-if="state.hud.hubs" title="Relations summarised on the tables instead of drawn (HUBS)">
                        HUBS <b t-esc="state.hud.hubs"/> · <t t-esc="fmt(state.hud.collapsed)"/> collapsed
                    </span>
                    <span t-if="state.perf.isolated" title="Tables without drawn relations, packed in the grid below the drawing">
                        ISOLATED <b t-esc="fmt(state.perf.isolated)"/>
                    </span>
                    <span t-if="state.seedNames.length and state.scope === 'neighborhood'" class="dv_sx_seeds">
                        FROM <b t-esc="state.seedNames.join(', ')"/>
                    </span>
                </div>

                <canvas t-if="state.showMinimap and state.gfx.minimap !== 'off'" class="dv_sx_minimap" t-ref="minimap"
                        width="220" height="150" t-on-click="onMinimapClick"/>

                <!-- inspector -->
                <div t-if="state.inspector" class="dv_sx_inspector">
                    <t t-if="state.inspector.kind === 'table'">
                        <div class="dv_sx_ins_head">
                            <span class="dv_sx_ins_type" t-esc="state.inspector.type"/>
                            <span class="dv_sx_ins_schema" t-esc="state.inspector.schema"/>
                            <div class="dv_sx_ins_title" t-esc="state.inspector.name"/>
                        </div>
                        <dl class="dv_sx_ins_facts">
                            <dt>Columns</dt><dd t-esc="state.inspector.columns"/>
                            <dt>PK / FK</dt><dd><t t-esc="state.inspector.pk"/> / <t t-esc="state.inspector.fk"/></dd>
                            <dt>Outgoing</dt><dd t-esc="state.inspector.outgoing"/>
                            <dt>Incoming</dt><dd t-esc="state.inspector.incoming"/>
                        </dl>
                        <p t-if="state.inspector.comment" class="dv_sx_ins_note" t-esc="state.inspector.comment"/>
                        <p t-if="state.inspector.hubCollapsed" class="dv_sx_ins_note">
                            Hub: <b t-esc="state.inspector.hubCollapsed"/> incoming relations are summarised on the referencing tables.
                        </p>
                        <div t-if="state.inspector.change" class="dv_sx_ins_diff" t-att-data-change="state.inspector.change">
                            <div class="dv_sx_ins_diff_head">
                                <span t-att-class="'dv_sx_chip is-' + state.inspector.change" t-esc="changeSign(state.inspector.change) + ' ' + state.inspector.change.toUpperCase()"/>
                                <small t-if="state.inspector.ghost">drawn from FROM (removed in TO)</small>
                            </div>
                            <t t-if="state.inspector.detailsLoaded">
                                <ul class="dv_sx_ins_changes">
                                    <li t-foreach="state.inspector.objectChanges" t-as="oc" t-key="oc.key" t-att-class="'is-' + oc.change">
                                        <b t-esc="oc.sign"/> <t t-esc="oc.label"/>
                                        <div t-foreach="oc.props" t-as="pp" t-key="pp_index" class="dv_sx_ins_prop" t-esc="pp"/>
                                    </li>
                                    <li t-foreach="state.inspector.columnChanges" t-as="cc" t-key="cc.key" t-att-class="'is-' + cc.change">
                                        <b t-esc="cc.sign"/> column <b t-esc="cc.name"/> <small t-esc="cc.type"/>
                                        <div t-foreach="cc.props" t-as="pp" t-key="pp_index" class="dv_sx_ins_prop" t-esc="pp"/>
                                    </li>
                                </ul>
                                <p t-if="state.inspector.change === 'unchanged'" class="dv_sx_ins_note">No change in this table.</p>
                            </t>
                            <p t-else="" class="dv_sx_ins_note">Column-level changes load when the table is drawn with columns (zoom in or “All columns”).</p>
                        </div>
                        <div class="dv_sx_ins_actions">
                            <button t-on-click="() => this.exploreFrom(state.inspector.id)">Explore from here</button>
                            <button t-if="!state.inspector.isSeed" t-on-click="() => this.addSeed(state.inspector.id)">Add to exploration</button>
                            <button t-if="state.inspector.visible" t-on-click="() => this.toggleCollapse(state.inspector.id)"
                                    t-esc="state.inspector.collapsed ? 'Expand' : 'Collapse'"/>
                            <button t-if="state.inspector.visible and !state.inspector.collapsed"
                                    t-on-click="() => this.toggleExpand(state.inspector.id)"
                                    t-esc="state.inspector.expanded ? 'Default detail' : 'All columns'"/>
                            <button t-if="state.inspector.hubCollapsed or state.inspector.hubExpanded"
                                    t-on-click="() => this.toggleHub(state.inspector.id)"
                                    t-esc="state.inspector.hubExpanded ? 'Collapse hub relations' : 'Draw hub relations'"/>
                            <button t-on-click="() => this.openRecord('table', state.inspector.id)">Open record</button>
                        </div>
                    </t>
                    <t t-else="">
                        <div class="dv_sx_ins_head">
                            <span class="dv_sx_ins_type" t-att-class="{ 'is-implied': state.inspector.implied }"
                                  t-esc="state.inspector.implied ? 'implied relation' : 'foreign key'"/>
                            <div class="dv_sx_ins_title" t-esc="state.inspector.name"/>
                        </div>
                        <div t-if="state.inspector.change" class="dv_sx_ins_diff" t-att-data-change="state.inspector.change">
                            <span t-att-class="'dv_sx_chip is-' + state.inspector.change" t-esc="changeSign(state.inspector.change) + ' ' + state.inspector.change.toUpperCase()"/>
                            <small t-if="state.inspector.ghost">relation existed in FROM only</small>
                        </div>
                        <ul class="dv_sx_ins_pairs">
                            <li t-foreach="state.inspector.pairs" t-as="p" t-key="p_index" t-esc="p"/>
                        </ul>
                        <dl class="dv_sx_ins_facts">
                            <dt>Kind</dt>
                            <dd t-esc="state.inspector.implied ? 'Heuristic (SchemaSpy) — NOT enforced by the DB' : 'Enforced by the database'"/>
                            <dt>Cardinality</dt><dd><t t-esc="state.inspector.cardinality or '—'"/> <small>(derived)</small></dd>
                            <dt>Parent</dt><dd t-esc="state.inspector.optional ? 'optional (nullable FK)' : 'mandatory'"/>
                            <dt>On delete</dt><dd t-esc="state.inspector.onDeleteCascade ? 'CASCADE' : 'no cascade'"/>
                            <dt t-if="state.inspector.onUpdate">On update</dt>
                            <dd t-if="state.inspector.onUpdate" t-esc="state.inspector.onUpdate"/>
                        </dl>
                        <div class="dv_sx_ins_actions">
                            <button t-on-click="() => this.selectTable(state.inspector.sourceId)">Child table</button>
                            <button t-on-click="() => this.selectTable(state.inspector.targetId)">Parent table</button>
                            <button t-on-click="() => this.openRecord('relation', state.inspector.id)">Open record</button>
                        </div>
                    </t>
                </div>

                <!-- custom graphics settings -->
                <div t-if="state.gfx.panel and state.gfx.preset === 'custom'" class="dv_sx_gfx_panel">
                    <div class="dv_sx_perf_title">GRAPHICS · CUSTOM</div>
                    <div class="dv_sx_gfx_section">Visual effects</div>
                    <label>Glow
                        <select t-on-change="(ev) => this.setGraphicsKnob('glow', ev.target.value)">
                            <t t-foreach="knobOptions('glow')" t-as="o" t-key="o">
                                <option t-att-value="o" t-att-selected="state.gfx.settings.glow === o" t-esc="o"/>
                            </t>
                        </select>
                    </label>
                    <label><input type="checkbox" t-att-checked="state.gfx.settings.shadows"
                                  t-on-change="(ev) => this.setGraphicsKnob('shadows', ev.target.checked)"/> Shadows</label>
                    <label><input type="checkbox" t-att-checked="state.gfx.settings.edgeAnimation"
                                  t-on-change="(ev) => this.setGraphicsKnob('edgeAnimation', ev.target.checked)"/> Edge animation</label>
                    <label><input type="checkbox" t-att-checked="state.gfx.settings.selectionPulse"
                                  t-on-change="(ev) => this.setGraphicsKnob('selectionPulse', ev.target.checked)"/> Selection pulse</label>
                    <label>Background
                        <select t-on-change="(ev) => this.setGraphicsKnob('background', ev.target.value)">
                            <t t-foreach="knobOptions('background')" t-as="o" t-key="o">
                                <option t-att-value="o" t-att-selected="state.gfx.settings.background === o" t-esc="o"/>
                            </t>
                        </select>
                    </label>
                    <label>Transitions
                        <select t-on-change="(ev) => this.setGraphicsKnob('transitions', ev.target.value)">
                            <t t-foreach="knobOptions('transitions')" t-as="o" t-key="o">
                                <option t-att-value="o" t-att-selected="state.gfx.settings.transitions === o" t-esc="o"/>
                            </t>
                        </select>
                    </label>
                    <div class="dv_sx_gfx_section">Rendering</div>
                    <label>Level of detail
                        <select t-on-change="(ev) => this.setGraphicsKnob('lod', ev.target.value)">
                            <t t-foreach="knobOptions('lod')" t-as="o" t-key="o">
                                <option t-att-value="o" t-att-selected="state.gfx.settings.lod === o" t-esc="o"/>
                            </t>
                        </select>
                    </label>
                    <label>Viewport culling
                        <select t-on-change="(ev) => this.setGraphicsKnob('culling', ev.target.value)">
                            <t t-foreach="knobOptions('culling')" t-as="o" t-key="o">
                                <option t-att-value="o" t-att-selected="state.gfx.settings.culling === o" t-esc="o"/>
                            </t>
                        </select>
                    </label>
                    <label>Max detailed tables
                        <select t-on-change="(ev) => this.setGraphicsKnob('detailedNodes', Number(ev.target.value))">
                            <t t-foreach="knobOptions('detailedNodes')" t-as="o" t-key="o">
                                <option t-att-value="o" t-att-selected="state.gfx.settings.detailedNodes === o" t-esc="o"/>
                            </t>
                        </select>
                    </label>
                    <div class="dv_sx_gfx_section">Labels</div>
                    <label>Relation labels
                        <select t-on-change="(ev) => this.setGraphicsKnob('relationLabels', Number(ev.target.value))">
                            <t t-foreach="knobOptions('relationLabels')" t-as="o" t-key="o">
                                <option t-att-value="o" t-att-selected="state.gfx.settings.relationLabels === o" t-esc="o === 0 ? 'none' : 'max ' + o"/>
                            </t>
                        </select>
                    </label>
                    <label><input type="checkbox" t-att-checked="state.gfx.settings.columnTypes"
                                  t-on-change="(ev) => this.setGraphicsKnob('columnTypes', ev.target.checked)"/> Column types</label>
                    <label><input type="checkbox" t-att-checked="state.gfx.settings.secondary"
                                  t-on-change="(ev) => this.setGraphicsKnob('secondary', ev.target.checked)"/> Secondary metadata (tooltips)</label>
                    <div class="dv_sx_gfx_section">Minimap</div>
                    <label>Detail
                        <select t-on-change="(ev) => this.setGraphicsKnob('minimap', ev.target.value)">
                            <t t-foreach="knobOptions('minimap')" t-as="o" t-key="o">
                                <option t-att-value="o" t-att-selected="state.gfx.settings.minimap === o" t-esc="o"/>
                            </t>
                        </select>
                    </label>
                </div>

                <!-- performance panel -->
                <div t-if="state.showPerf" class="dv_sx_perf">
                    <div class="dv_sx_perf_title">PERFORMANCE</div>
                    <table>
                        <tr><td>Tables visible / rows drawn</td><td><t t-esc="fmt(state.perf.tables)"/> / <t t-esc="fmt(state.perf.rows)"/></td></tr>
                        <tr><td>Relations visible</td><td t-esc="fmt(state.perf.relations)"/></tr>
                        <tr><td>Snapshot columns (total)</td><td t-esc="fmt(state.perf.columnsTotal)"/></tr>
                        <tr><td>Index fetch / model build</td><td><t t-esc="ms(state.perf.fetchIndexMs)"/> / <t t-esc="ms(state.perf.modelMs)"/></td></tr>
                        <tr><td>Column details fetched</td><td><t t-esc="fmt(state.perf.detailsFetched)"/> (<t t-esc="ms(state.perf.detailsMs)"/>)</td></tr>
                        <tr><td>Graph build</td><td t-esc="ms(state.perf.buildMs)"/></tr>
                        <tr><td>ELK layout</td><td><t t-esc="ms(state.perf.layoutMs)"/> · <t t-esc="state.perf.layoutMode"/></td></tr>
                        <tr><td>JointJS first render</td><td t-esc="ms(state.perf.renderMs)"/></tr>
                        <tr><td>Cells (model) / mounted (DOM)</td><td><t t-esc="fmt(state.perf.cells)"/> / <t t-esc="fmt(state.perf.mounted)"/></td></tr>
                        <tr><td>SVG nodes</td><td t-esc="fmt(state.perf.svgNodes)"/></tr>
                        <tr><td>Viewport culling</td><td t-esc="state.perf.culling ? 'on' : 'off (small graph)'"/></tr>
                        <tr><td>Zoom / LOD</td><td><t t-esc="state.perf.scale and state.perf.scale.toFixed(2)"/> · <t t-esc="state.perf.lod"/></td></tr>
                        <tr><td>Pinned (manual)</td><td t-esc="fmt(state.perf.pinned)"/></tr>
                        <tr><td>Graphics preset / level</td><td><t t-esc="levelLabel(state.gfx.preset)"/> / <t t-esc="levelLabel(state.gfx.level)"/></td></tr>
                        <tr><td>Frame rate (interaction)</td><td t-esc="state.gfx.fps ? state.gfx.fps + ' fps' : '—'"/></tr>
                        <tr t-if="state.gfx.preset === 'auto'"><td>AUTO</td><td class="dv_sx_perf_wrap" t-esc="state.gfx.autoAction"/></tr>
                        <tr><td>Effects</td><td t-esc="'glow ' + state.gfx.settings.glow + (state.gfx.settings.shadows ? ' · shadows' : '') + (state.gfx.settings.edgeAnimation ? ' · edge anim' : '') + (state.gfx.settings.selectionPulse ? ' · pulse' : '') + ' · bg ' + state.gfx.settings.background"/></tr>
                        <tr><td>LOD / culling</td><td t-esc="state.gfx.settings.lod + ' / ' + state.gfx.settings.culling"/></tr>
                    </table>
                    <div class="dv_sx_perf_foot" t-esc="state.perf.versions"/>
                </div>

                <div class="dv_sx_legend">
                    <span><i class="dv_sx_lg_real"/> FK</span>
                    <span><i class="dv_sx_lg_implied"/> implied (heuristic)</span>
                    <span><i class="dv_sx_lg_in"/> incoming</span>
                    <span><i class="dv_sx_lg_out"/> outgoing</span>
                </div>
            </div>
        </div>
    </t>
</templates>
`;
