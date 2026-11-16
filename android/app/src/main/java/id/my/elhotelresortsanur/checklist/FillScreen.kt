package id.my.elhotelresortsanur.checklist

import android.net.Uri
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.verticalScroll
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.border
import androidx.compose.ui.res.painterResource
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.Checkbox
import androidx.compose.material3.CheckboxDefaults
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateMapOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.produceState
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.content.FileProvider
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import org.json.JSONArray
import org.json.JSONObject
import java.io.File

private val STATUSES = listOf("done" to "Done", "ongoing" to "Ongoing", "pending" to "Pending")
private fun statusColor(status: String?) = when (status) { "done" -> Green; "ongoing" -> Amber; "pending" -> Red; else -> Muted }

/** Halaman isi checklist: tiap pilihan tersimpan otomatis (ke HP dulu, lalu dikirim). */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun FillScreen(repo: Repo, id: Long, onBack: () -> Unit) {
    val context = androidx.compose.ui.platform.LocalContext.current
    val scope = rememberCoroutineScope()
    val version by repo.version.collectAsState()
    val detail = remember(version) { repo.cachedDetail(id)?.let(::parseDetail) }
    LaunchedEffect(id) { repo.refreshDetail(id) }

    /** Menulis isian ke cache, memasukkannya ke antrean, lalu mencoba mengirim. */
    fun save(item: Item, values: Map<Long, String?>, note: String?, status: String? = null) {
        val json = repo.cachedDetail(id) ?: return
        applyItem(json, item.id, values, note, status)
        repo.saveDetail(id, json)
        val body = JSONObject().put("note", note ?: JSONObject.NULL)
        if (item.params.isEmpty()) {
            body.put("status", status ?: JSONObject.NULL)
        } else {
            // Semua nilai dikirim (bukan hanya yang berubah) supaya isian yang digabung di antrean tetap utuh.
            val all = item.params.associate { it.id to it.value } + values
            body.put("parameters", JSONObject().apply { all.forEach { (k, v) -> put(k.toString(), v ?: JSONObject.NULL) } })
        }
        repo.saveItem(id, item.id, body)
        scope.launch { repo.flush() }
    }

    var cardMode by remember { mutableStateOf(repo.cardView) }
    var gridOpen by remember { mutableStateOf(false) }

    Scaffold(
        topBar = {
            TopAppBar(
                title = {
                    Column {
                        Text(detail?.summary?.employee ?: detail?.summary?.position ?: "Checklist", fontWeight = FontWeight.SemiBold)
                        detail?.let { Text(listOfNotNull(it.summary.position.takeIf { _ -> it.summary.employee != null }, formatDate(it.summary.date)).joinToString(" · "), fontSize = 12.sp, color = Color(0xFFDCCDB6)) }
                    }
                },
                navigationIcon = { BackButton(onBack) },
                actions = {
                    if (detail != null) {
                        if (cardMode) IconButton({ gridOpen = true }) { Icon(painterResource(R.drawable.ic_apps), "Lihat semua tugas", tint = Color.White) }
                        IconButton({ cardMode = !cardMode; repo.cardView = cardMode }) {
                            Icon(painterResource(if (cardMode) R.drawable.ic_list else R.drawable.ic_cards), if (cardMode) "Tampilan daftar" else "Tampilan kartu", tint = Color.White)
                        }
                    }
                },
                colors = TopAppBarDefaults.topAppBarColors(containerColor = Ink, titleContentColor = Color.White),
            )
        },
        containerColor = Cream,
    ) { padding ->
        Column(Modifier.padding(padding).imePadding()) {
            Banner(repo)
            if (detail == null) {
                Loading()
                return@Column
            }
            val editable = detail.summary.canEdit
            val progress = localProgress(detail)
            if (cardMode) {
                CardDeck(repo, detail, editable, progress, gridOpen, { gridOpen = false }, { gridOpen = true }, ::save, Modifier.weight(1f))
            } else LazyColumn(contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                item { ProgressHeader(progress, detail) }
                if (!editable) item { LockedNotice(detail) }
                var section: String? = null
                detail.items.forEach { item ->
                    if (item.section != null && item.section != section) {
                        section = item.section
                        item(key = "s-${item.id}") { Text(item.section.orEmpty(), fontWeight = FontWeight.SemiBold, color = Gold, modifier = Modifier.padding(top = 8.dp)) }
                    }
                    item(key = item.id) { TaskCard(repo, detail, item, editable, ::save) }
                }
                item(key = "extras") { ExtrasCard(repo, detail, editable) }
                item(key = "note") { GeneralNote(repo, detail, editable) }
                if (detail.summary.canReview) item(key = "review") { ReviewButton(repo, detail, progress) }
            }
        }
    }
}

@Composable
private fun LockedNotice(detail: Detail) = Text(
    when (detail.summary.status) {
        "reviewed" -> "Checklist ini sudah direview GM/HR dan terkunci."
        "hod_reviewed" -> "Checklist ini sudah direview HOD dan terkunci."
        else -> "Anda hanya bisa melihat checklist ini."
    },
    Modifier.fillMaxWidth().background(GoldSoft, RoundedCornerShape(12.dp)).padding(12.dp), fontSize = 14.sp,
)

/** HOD / GM / HR: tandai sudah direview bila lengkap dan semua isian sudah terkirim (server memeriksa lagi). */
@Composable
private fun ReviewButton(repo: Repo, detail: Detail, progress: Progress) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val id = detail.summary.id
    val waiting = repo.pendingFor(id)
    Button(
        onClick = { scope.launch { repo.call { it.send("POST", "/checklists/$id/review") }?.let { res -> repo.message.value = null; android.widget.Toast.makeText(context, res.s("message"), android.widget.Toast.LENGTH_LONG).show() }; repo.refreshDetail(id) } },
        enabled = progress.complete && waiting == 0, modifier = Modifier.fillMaxWidth().height(50.dp), colors = ButtonDefaults.buttonColors(containerColor = Green),
    ) { Text(if (waiting > 0) "Kirim isian dulu sebelum review" else "Tandai sudah direview") }
}

// ---- Tampilan kartu: satu tugas per layar, geser kiri / kanan ----

private fun segmentColor(status: String?) = when (status) { "done" -> Green; "ongoing" -> Amber; "pending" -> Red; else -> Color(0xFFE2D8C6) }

/**
 * Seperti kartu di web: geser kiri / kanan (atau panah di bawah) antar tugas; segmen berwarna di atas = status tiap tugas (ketuk untuk loncat);
 * Done membuka tugas berikutnya sendiri (Ongoing / Pending tidak pindah); dibuka di tugas pertama yang belum diisi; layar terakhir = Ringkasan.
 */
@OptIn(androidx.compose.foundation.ExperimentalFoundationApi::class)
@Composable
private fun CardDeck(
    repo: Repo, detail: Detail, editable: Boolean, progress: Progress, gridOpen: Boolean, onGridClose: () -> Unit, onGridOpen: () -> Unit,
    save: (Item, Map<Long, String?>, String?, String?) -> Unit, modifier: Modifier,
) {
    val scope = rememberCoroutineScope()
    val total = detail.items.size
    val start = remember(detail.summary.id) { detail.items.indexOfFirst { it.status == null }.let { if (it < 0) total else it } }
    val pager = androidx.compose.foundation.pager.rememberPagerState(initialPage = start) { total + 1 }
    val page = pager.currentPage

    fun jump(to: Int) { scope.launch { pager.animateScrollToPage(to.coerceIn(0, total)) } }
    // Done: tunggu sebentar supaya terlihat, lalu pindah bila tugas itu memang sudah Done (tugas berparameter bisa masih kurang isiannya).
    fun advanceIfDone(itemId: Long, from: Int) = scope.launch {
        delay(350)
        val status = repo.cachedDetail(detail.summary.id)?.let(::parseDetail)?.items?.firstOrNull { it.id == itemId }?.status
        if (status == "done" && pager.currentPage == from) pager.animateScrollToPage((from + 1).coerceAtMost(total))
    }

    Column(modifier) {
        Row(Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
            Text("${progress.filled}/${progress.total} terisi", fontWeight = FontWeight.SemiBold, color = Ink, fontSize = 14.sp, modifier = Modifier.weight(1f))
            Text(if (progress.complete) "Lengkap ✓" else progress.score?.let { "Skor $it%" } ?: "", color = if (progress.complete) Green else Muted, fontSize = 14.sp)
        }
        Row(Modifier.fillMaxWidth().padding(horizontal = 16.dp).height(16.dp), horizontalArrangement = Arrangement.spacedBy(2.dp), verticalAlignment = Alignment.CenterVertically) {
            detail.items.forEachIndexed { i, item ->
                Box(Modifier.weight(1f).height(if (i == page) 12.dp else 6.dp).clip(RoundedCornerShape(3.dp)).background(segmentColor(item.status)).clickable { jump(i) })
            }
            Box(Modifier.weight(1f).height(if (page == total) 12.dp else 6.dp).clip(RoundedCornerShape(3.dp)).background(Gold).clickable { jump(total) })
        }
        if (!editable) Box(Modifier.padding(horizontal = 16.dp, vertical = 8.dp)) { LockedNotice(detail) }

        androidx.compose.foundation.pager.HorizontalPager(
            state = pager, modifier = Modifier.weight(1f).fillMaxWidth(),
            key = { if (it < total) detail.items[it].id else -1L },
        ) { index ->
            Column(
                Modifier.fillMaxSize().verticalScroll(androidx.compose.foundation.rememberScrollState()).padding(horizontal = 16.dp, vertical = 8.dp),
                verticalArrangement = Arrangement.spacedBy(12.dp),
            ) {
                if (index < total) {
                    val item = detail.items[index]
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Text("Tugas ${index + 1} dari $total", color = Gold, fontWeight = FontWeight.SemiBold, fontSize = 13.sp, modifier = Modifier.weight(1f))
                        item.section?.let { Text(it, color = Muted, fontSize = 13.sp) }
                    }
                    TaskCard(repo, detail, item, editable, save) { advanceIfDone(item.id, index) }
                } else {
                    Text("Ringkasan", color = Gold, fontWeight = FontWeight.SemiBold, fontSize = 13.sp)
                    ProgressHeader(progress, detail)
                    if (progress.filled < total) OutlinedButton(onGridOpen, Modifier.fillMaxWidth()) { Text("Lihat tugas yang belum terisi (${total - progress.filled})") }
                    ExtrasCard(repo, detail, editable)
                    GeneralNote(repo, detail, editable)
                    if (detail.summary.canReview) ReviewButton(repo, detail, progress)
                }
                Spacer(Modifier.height(16.dp))
            }
        }

        Row(Modifier.fillMaxWidth().background(Color.White).padding(horizontal = 8.dp, vertical = 2.dp), verticalAlignment = Alignment.CenterVertically) {
            IconButton({ jump(page - 1) }, enabled = page > 0) { Icon(painterResource(R.drawable.ic_chevron_left), "Tugas sebelumnya", tint = if (page > 0) Ink else Muted.copy(alpha = 0.3f)) }
            Text(if (page < total) "${page + 1} / $total" else "Ringkasan", Modifier.weight(1f), textAlign = androidx.compose.ui.text.style.TextAlign.Center, fontWeight = FontWeight.SemiBold, color = Ink)
            IconButton({ jump(page + 1) }, enabled = page < total) { Icon(painterResource(R.drawable.ic_chevron_right), "Tugas berikutnya", tint = if (page < total) Ink else Muted.copy(alpha = 0.3f)) }
        }
    }

    if (gridOpen) TaskGridDialog(detail.items, page, onPick = { onGridClose(); jump(it) }, onClose = onGridClose)
}

/** "Lihat semua tugas": kotak bernomor berwarna menurut status; ketuk untuk loncat. */
@OptIn(androidx.compose.foundation.layout.ExperimentalLayoutApi::class)
@Composable
private fun TaskGridDialog(items: List<Item>, current: Int, onPick: (Int) -> Unit, onClose: () -> Unit) {
    AlertDialog(
        onDismissRequest = onClose,
        title = { Text("Semua tugas") },
        text = {
            Column(Modifier.verticalScroll(androidx.compose.foundation.rememberScrollState()), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    items.forEachIndexed { i, item ->
                        val filled = item.status != null
                        Box(
                            Modifier.size(44.dp).clip(RoundedCornerShape(10.dp)).background(if (filled) segmentColor(item.status) else Cream)
                                .border(if (i == current) 2.dp else 1.dp, if (i == current) Ink else Color(0xFFE2D8C6), RoundedCornerShape(10.dp))
                                .clickable { onPick(i) },
                            contentAlignment = Alignment.Center,
                        ) { Text("${i + 1}", color = if (filled) Color.White else Ink, fontWeight = FontWeight.SemiBold) }
                    }
                }
                Text("Hijau Done · kuning Ongoing · merah Pending · putih belum diisi", color = Muted, fontSize = 12.sp)
            }
        },
        confirmButton = { TextButton({ onPick(items.size) }) { Text("Ringkasan") } },
        dismissButton = { TextButton(onClose) { Text("Tutup") } },
    )
}

@Composable
private fun ProgressHeader(progress: Progress, detail: Detail) {
    Card(colors = CardDefaults.cardColors(containerColor = Color.White), shape = RoundedCornerShape(16.dp)) {
        Column(Modifier.padding(16.dp).fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Row {
                Text("${progress.filled}/${progress.total} tugas terisi", fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f))
                Text(if (progress.complete) "Lengkap ✓" else progress.score?.let { "Skor $it%" } ?: "", color = if (progress.complete) Green else Ink)
            }
            LinearProgressIndicator(
                progress = { if (progress.total == 0) 0f else progress.filled.toFloat() / progress.total },
                modifier = Modifier.fillMaxWidth().height(8.dp), color = Gold, trackColor = GoldSoft,
            )
            val missingReasons = if (detail.pendingNoteRequired) detail.items.count { it.status == "pending" && it.note.isNullOrBlank() } else 0
            if (missingReasons > 0) Text("$missingReasons tugas Pending belum diberi alasan.", color = Red, fontSize = 13.sp)
        }
    }
}

@OptIn(androidx.compose.foundation.layout.ExperimentalLayoutApi::class)
@Composable
private fun TaskCard(repo: Repo, detail: Detail, item: Item, editable: Boolean, save: (Item, Map<Long, String?>, String?, String?) -> Unit, onDone: () -> Unit = {}) {
    var showStandard by remember { mutableStateOf(false) }
    var noteOpen by rememberSaveable(item.id) { mutableStateOf(false) }
    val showNote = noteOpen || item.status == "pending" || item.status == "ongoing" || !item.note.isNullOrBlank()

    Card(
        colors = CardDefaults.cardColors(containerColor = Color.White), shape = RoundedCornerShape(16.dp),
        border = BorderStroke(1.dp, if (item.status != null) statusColor(item.status).copy(alpha = 0.5f) else Color(0xFFEADFCE)),
    ) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Text(item.title, fontWeight = FontWeight.Medium, color = Ink, fontSize = 16.sp)
            if (!item.standard.isNullOrBlank()) {
                Text(if (showStandard) item.standard else "Cara / standar ›", color = Muted, fontSize = 13.sp, modifier = Modifier.clickable { showStandard = !showStandard })
            }

            if (item.params.isEmpty()) {
                StatusButtons(item.status, editable) { save(item, emptyMap(), item.note, it); if (it == "done") onDone() }
            }
            item.params.forEach { p -> ParamInput(p, item.params.size > 1, editable, onDone) { value -> save(item, mapOf(p.id to value), item.note, null) } }

            if (showNote) {
                DebouncedField(
                    key = "note-${item.id}", initial = item.note.orEmpty(), editable = editable,
                    label = if (item.status == "pending" && detail.pendingNoteRequired) "Alasan Pending (wajib)" else "Catatan",
                    isError = item.status == "pending" && detail.pendingNoteRequired && item.note.isNullOrBlank(),
                ) { save(item, emptyMap(), it.ifBlank { null }, item.status.takeIf { item.params.isEmpty() }) }
            }

            PhotoRow(repo, detail, item, editable)

            if (editable && !showNote) TextButton(onClick = { noteOpen = true }) { Text("+ Catatan") }
        }
    }
}

@Composable
private fun StatusButtons(current: String?, editable: Boolean, onPick: (String?) -> Unit) {
    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        STATUSES.forEach { (value, label) ->
            val selected = current == value
            val color = statusColor(value)
            Box(
                Modifier.weight(1f).height(48.dp).clip(RoundedCornerShape(12.dp))
                    .background(if (selected) color else color.copy(alpha = 0.08f))
                    // Menekan lagi pilihan yang sedang terpilih = kosongkan (sama dengan web).
                    .clickable(enabled = editable) { onPick(if (selected) null else value) },
                contentAlignment = Alignment.Center,
            ) {
                Text(label, color = if (selected) Color.White else color, fontWeight = FontWeight.SemiBold)
            }
        }
    }
}

@OptIn(androidx.compose.foundation.layout.ExperimentalLayoutApi::class)
@Composable
private fun ParamInput(p: Param, showName: Boolean, editable: Boolean, onDone: () -> Unit, onChange: (String?) -> Unit) {
    Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
        if (showName && p.type != "checkbox") Text(p.name + (p.unit?.let { " ($it)" } ?: ""), fontSize = 14.sp, color = Ink)
        when (p.type) {
            "status" -> StatusButtons(p.value, editable) { onChange(it); if (it == "done") onDone() }
            "number", "meter" -> {
                val range = listOfNotNull(p.min?.let { "min ${fmt(it)}" }, p.max?.let { "maks ${fmt(it)}" }).joinToString(" · ")
                DebouncedField(
                    key = "p-${p.id}", initial = p.value.orEmpty(), editable = editable, label = if (showName) range.ifEmpty { "Angka" } else p.name + (p.unit?.let { " ($it)" } ?: ""),
                    keyboard = KeyboardType.Decimal, validate = { it.isBlank() || it.replace(',', '.').toDoubleOrNull() != null },
                    supporting = range.takeIf { !showName && it.isNotEmpty() },
                ) { onChange(it.ifBlank { null }) }
            }
            "option" -> FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                p.options.forEach { option ->
                    val selected = p.value == option
                    val color = if (p.normal == null || p.normal == option) Green else Red
                    Text(
                        option,
                        Modifier.clip(RoundedCornerShape(20.dp)).background(if (selected) color else Color.White)
                            .clickable(enabled = editable) { onChange(if (selected) null else option) }
                            .padding(horizontal = 14.dp, vertical = 10.dp),
                        color = if (selected) Color.White else Ink,
                    )
                }
            }
            "checkbox" -> Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.clickable(enabled = editable) { onChange(if (p.value == "1") null else "1") }) {
                Checkbox(p.value == "1", { onChange(if (it) "1" else null) }, enabled = editable, colors = CheckboxDefaults.colors(checkedColor = Gold))
                Text(p.name)
            }
            else -> DebouncedField(key = "p-${p.id}", initial = p.value.orEmpty(), editable = editable, label = if (showName) "Isi" else p.name) { onChange(it.ifBlank { null }) }
        }
    }
}

private fun fmt(n: Double) = if (n % 1.0 == 0.0) n.toLong().toString() else n.toString()

/** Kolom teks yang menyimpan 700 ms setelah berhenti mengetik (sama dengan web). */
@Composable
private fun DebouncedField(
    key: String, initial: String, editable: Boolean, label: String, isError: Boolean = false,
    keyboard: KeyboardType = KeyboardType.Text, validate: (String) -> Boolean = { true }, supporting: String? = null,
    onSave: (String) -> Unit,
) {
    var text by rememberSaveable(key) { mutableStateOf(initial) }
    var saved by rememberSaveable(key) { mutableStateOf(initial) }
    val valid = validate(text)
    LaunchedEffect(text) {
        if (text == saved || !valid) return@LaunchedEffect
        delay(700)
        saved = text
        onSave(text)
    }
    OutlinedTextField(
        text, { text = it }, Modifier.fillMaxWidth(), enabled = editable, label = { Text(label) },
        isError = isError || !valid, keyboardOptions = KeyboardOptions(keyboardType = keyboard),
        supportingText = (if (!valid) "Isi dengan angka, mis. 220 atau 220,5." else supporting)?.let { { Text(it) } },
    )
}

/** Tugas tambahan hari itu (di luar daftar, tidak dihitung skor): judul, status, catatan. Tersimpan otomatis seperti isian lain. */
@Composable
private fun ExtrasCard(repo: Repo, detail: Detail, editable: Boolean) {
    val id = detail.summary.id
    val scope = rememberCoroutineScope()
    val version by repo.version.collectAsState()
    val extras = remember(version) { repo.cachedDetail(id)?.optJSONArray("extras").objects() }
    if (!editable && extras.isEmpty()) return

    fun update(send: Boolean = true, change: (JSONArray) -> Unit) {
        val json = repo.cachedDetail(id) ?: return
        val list = json.optJSONArray("extras") ?: JSONArray().also { json.put("extras", it) }
        change(list)
        repo.saveDetail(id, json)
        if (send) { repo.saveExtras(id, list); scope.launch { repo.flush() } }
    }

    Card(colors = CardDefaults.cardColors(containerColor = Color.White), shape = RoundedCornerShape(16.dp)) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Text("Tugas tambahan", fontWeight = FontWeight.SemiBold)
            Text("Di luar daftar tugas; tidak dihitung skor.", color = Muted, fontSize = 13.sp)
            extras.forEachIndexed { i, extra ->
                // uid lokal supaya kolom tidak tertukar saat baris dihapus.
                val uid = extra.optString("uid").ifEmpty { "srv-$i-${extra.optString("title")}" }
                Column(Modifier.fillMaxWidth().background(Cream, RoundedCornerShape(12.dp)).padding(10.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    DebouncedField(key = "extra-$id-$uid", initial = extra.s("title").orEmpty(), editable = editable, label = "Tugas") { text ->
                        update { it.getJSONObject(i).put("title", text) }
                    }
                    StatusButtons(extra.s("status"), editable) { status -> update { it.getJSONObject(i).put("status", status ?: JSONObject.NULL) } }
                    DebouncedField(key = "extra-note-$id-$uid", initial = extra.s("note").orEmpty(), editable = editable, label = "Catatan") { text ->
                        update { it.getJSONObject(i).put("note", text.ifBlank { null } ?: JSONObject.NULL) }
                    }
                    if (editable) TextButton(onClick = { update { it.remove(i) } }) { Text("Hapus", color = Red) }
                }
            }
            if (editable && extras.size < 20) OutlinedButton(onClick = {
                // Baris kosong hanya di HP sampai judulnya diisi (server membuang judul kosong).
                update(send = false) { it.put(JSONObject().put("uid", java.util.UUID.randomUUID().toString()).put("title", "").put("status", JSONObject.NULL).put("note", JSONObject.NULL)) }
            }, Modifier.fillMaxWidth()) { Text("+ Tambah tugas tambahan") }
        }
    }
}

@Composable
private fun GeneralNote(repo: Repo, detail: Detail, editable: Boolean) {
    val scope = rememberCoroutineScope()
    Card(colors = CardDefaults.cardColors(containerColor = Color.White), shape = RoundedCornerShape(16.dp)) {
        Column(Modifier.padding(16.dp)) {
            DebouncedField(key = "general-${detail.summary.id}", initial = detail.note.orEmpty(), editable = editable, label = "Catatan umum") { text ->
                val id = detail.summary.id
                repo.cachedDetail(id)?.let { repo.saveDetail(id, it.put("note", text.ifBlank { null } ?: JSONObject.NULL)) }
                repo.saveNote(id, text.ifBlank { null })
                scope.launch { repo.flush() }
            }
        }
    }
}

@OptIn(androidx.compose.foundation.layout.ExperimentalLayoutApi::class)
@Composable
private fun PhotoRow(repo: Repo, detail: Detail, item: Item, editable: Boolean) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val id = detail.summary.id
    val room = detail.photoMax - item.photos.size
    val required = detail.photoRequirement == "all" || (detail.photoRequirement == "pending" && item.status == "pending")
    var viewing by remember { mutableStateOf<Photo?>(null) }
    val cameraFile = remember { File(context.cacheDir, "camera/${item.id}.jpg").apply { parentFile?.mkdirs() } }

    fun add(uris: List<Uri>) = scope.launch {
        uris.take(room).forEach { uri ->
            val out = File(context.cacheDir, "camera/out-${System.nanoTime()}.jpg")
            val ok = withContext(Dispatchers.IO) { Photos.compress(context, uri, out) }
            if (!ok) { repo.message.value = "Foto tidak bisa dibaca."; return@forEach }
            val path = withContext(Dispatchers.IO) { repo.addPhoto(id, item.id, out) }
            repo.cachedDetail(id)?.let { addLocalPhoto(it, item.id, path); repo.saveDetail(id, it) }
        }
        repo.flush()
    }

    val camera = rememberLauncherForActivityResult(ActivityResultContracts.TakePicture()) { ok -> if (ok) add(listOf(Uri.fromFile(cameraFile))) }
    val gallery = rememberLauncherForActivityResult(ActivityResultContracts.PickMultipleVisualMedia(maxOf(2, detail.photoMax))) { add(it) }

    if (item.photos.isNotEmpty()) {
        FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            item.photos.forEach { photo -> Thumb(repo, id, photo, Modifier.size(72.dp).clickable { viewing = photo }) }
        }
    }
    if (required && item.photos.isEmpty()) Text("Foto wajib untuk tugas ini.", color = Red, fontSize = 13.sp)
    if (editable && room > 0) {
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            OutlinedButton(onClick = {
                camera.launch(FileProvider.getUriForFile(context, context.packageName + ".files", cameraFile))
            }, Modifier.weight(1f)) { Text("Kamera") }
            OutlinedButton(onClick = {
                gallery.launch(PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageOnly))
            }, Modifier.weight(1f)) { Text("Galeri") }
        }
    }

    viewing?.let { photo ->
        AlertDialog(
            onDismissRequest = { viewing = null },
            confirmButton = { TextButton(onClick = { viewing = null }) { Text("Tutup") } },
            dismissButton = if (editable) {
                {
                    TextButton(onClick = {
                        repo.deletePhoto(id, photo)
                        repo.cachedDetail(id)?.let { removePhoto(it, item.id, photo); repo.saveDetail(id, it) }
                        viewing = null
                        scope.launch { repo.flush() }
                    }) { Text("Hapus foto", color = Red) }
                }
            } else null,
            text = { Thumb(repo, id, photo, Modifier.fillMaxWidth().height(360.dp), edge = 1280, crop = false) },
        )
    }
}

private val thumbCache = android.util.LruCache<String, android.graphics.Bitmap>(40)

@Composable
private fun Thumb(repo: Repo, checklist: Long, photo: Photo, modifier: Modifier, edge: Int = 256, crop: Boolean = true) =
    RemoteImage("${photo.id ?: photo.local}@$edge", modifier, crop, if (photo.local != null) "⏳" else "📷") {
        if (photo.local != null) Photos.thumbnail(File(photo.local), edge)
        else Photos.thumbnail(repo.api.bytes("/checklists/$checklist/photos/${photo.id}"), edge)
    }

/** Gambar dari server / berkas, dimuat di latar belakang dan disimpan di cache memori. */
@Composable
fun RemoteImage(key: String, modifier: Modifier, crop: Boolean = true, placeholder: String = "📷", load: () -> android.graphics.Bitmap?) {
    val bitmap by produceState(thumbCache.get(key), key) {
        if (value != null) return@produceState
        value = withContext(Dispatchers.IO) { runCatching(load).getOrNull() }?.also { thumbCache.put(key, it) }
    }
    Box(modifier.clip(RoundedCornerShape(10.dp)).background(GoldSoft), contentAlignment = Alignment.Center) {
        bitmap?.let { Image(it.asImageBitmap(), null, Modifier.fillMaxSize(), contentScale = if (crop) ContentScale.Crop else ContentScale.Fit) }
            ?: Text(placeholder, fontSize = 20.sp)
    }
}
