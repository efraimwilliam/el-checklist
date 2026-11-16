package id.my.elhotelresortsanur.checklist

import android.net.Uri
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
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
import org.json.JSONObject
import java.io.File

private val STATUSES = listOf("done" to "Done", "ongoing" to "Ongoing", "pending" to "Pending")
private fun statusColor(status: String?) = when (status) { "done" -> Green; "ongoing" -> Amber; "pending" -> Red; else -> Muted }

/** Halaman isi checklist: tiap pilihan tersimpan otomatis (ke HP dulu, lalu dikirim). */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun FillScreen(repo: Repo, id: Long, onBack: () -> Unit) {
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

    Scaffold(
        topBar = {
            TopAppBar(
                title = {
                    Column {
                        Text(detail?.summary?.position ?: "Checklist", fontWeight = FontWeight.SemiBold)
                        detail?.let { Text(formatDate(it.summary.date), fontSize = 12.sp, color = Color(0xFFDCCDB6)) }
                    }
                },
                navigationIcon = { IconButton(onClick = onBack) { Icon(Icons.AutoMirrored.Filled.ArrowBack, "Kembali", tint = Color.White) } },
                colors = TopAppBarDefaults.topAppBarColors(containerColor = Ink, titleContentColor = Color.White),
            )
        },
        containerColor = Cream,
    ) { padding ->
        Column(Modifier.padding(padding).imePadding()) {
            Banner(repo)
            if (detail == null) {
                Text("Memuat checklist…", Modifier.padding(16.dp), color = Muted)
                return@Column
            }
            val editable = detail.summary.canEdit
            val progress = localProgress(detail)
            LazyColumn(contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                item { ProgressHeader(progress, detail) }
                if (!editable) item {
                    Text(
                        when (detail.summary.status) {
                            "reviewed" -> "Checklist ini sudah direview GM/HR dan terkunci."
                            "hod_reviewed" -> "Checklist ini sudah direview HOD dan terkunci."
                            else -> "Anda hanya bisa melihat checklist ini."
                        },
                        Modifier.fillMaxWidth().background(GoldSoft, RoundedCornerShape(12.dp)).padding(12.dp), fontSize = 14.sp,
                    )
                }
                var section: String? = null
                detail.items.forEach { item ->
                    if (item.section != null && item.section != section) {
                        section = item.section
                        item(key = "s-${item.id}") { Text(item.section.orEmpty(), fontWeight = FontWeight.SemiBold, color = Gold, modifier = Modifier.padding(top = 8.dp)) }
                    }
                    item(key = item.id) { TaskCard(repo, detail, item, editable, ::save) }
                }
                item(key = "note") { GeneralNote(repo, detail, editable) }
            }
        }
    }
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
private fun TaskCard(repo: Repo, detail: Detail, item: Item, editable: Boolean, save: (Item, Map<Long, String?>, String?, String?) -> Unit) {
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
                StatusButtons(item.status, editable) { save(item, emptyMap(), item.note, it) }
            }
            item.params.forEach { p -> ParamInput(p, item.params.size > 1, editable) { value -> save(item, mapOf(p.id to value), item.note, null) } }

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
private fun ParamInput(p: Param, showName: Boolean, editable: Boolean, onChange: (String?) -> Unit) {
    Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
        if (showName && p.type != "checkbox") Text(p.name + (p.unit?.let { " ($it)" } ?: ""), fontSize = 14.sp, color = Ink)
        when (p.type) {
            "status" -> StatusButtons(p.value, editable, onChange)
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
private fun Thumb(repo: Repo, checklist: Long, photo: Photo, modifier: Modifier, edge: Int = 256, crop: Boolean = true) {
    val key = "${photo.id ?: photo.local}@$edge"
    val bitmap by produceState(thumbCache.get(key), key) {
        if (value != null) return@produceState
        value = withContext(Dispatchers.IO) {
            runCatching {
                if (photo.local != null) Photos.thumbnail(File(photo.local), edge)
                else Photos.thumbnail(repo.api.bytes("/checklists/$checklist/photos/${photo.id}"), edge)
            }.getOrNull()
        }?.also { thumbCache.put(key, it) }
    }
    Box(modifier.clip(RoundedCornerShape(10.dp)).background(GoldSoft), contentAlignment = Alignment.Center) {
        bitmap?.let { Image(it.asImageBitmap(), null, Modifier.fillMaxSize(), contentScale = if (crop) ContentScale.Crop else ContentScale.Fit) }
            ?: Text(if (photo.local != null) "⏳" else "📷", fontSize = 20.sp)
    }
}
