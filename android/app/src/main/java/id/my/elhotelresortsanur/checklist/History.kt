package id.my.elhotelresortsanur.checklist

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.*
import androidx.compose.material3.pulltorefresh.PullToRefreshBox
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import kotlinx.coroutines.launch
import org.json.JSONObject
import java.time.LocalDate

/* Riwayat checklist (sama dengan web /checklists): Staff miliknya, HOD departemennya, HR / GM / Admin semua. Butuh internet. */

private val RANGES = listOf("today" to "Hari ini", "yesterday" to "Kemarin", "7" to "7 hari", "30" to "30 hari")
private val REVIEW = listOf(null to "Semua", "saved" to "Belum direview", "hod_reviewed" to "Direview HOD", "reviewed" to "Direview GM/HR")

@OptIn(ExperimentalMaterial3Api::class, ExperimentalLayoutApi::class)
@Composable
fun HistoryScreen(repo: Repo, onOpen: (Long) -> Unit, onBack: () -> Unit) {
    val scope = rememberCoroutineScope()
    var range by rememberSaveable { mutableStateOf("7") }
    var department by rememberSaveable { mutableStateOf<Long?>(null) }
    var status by rememberSaveable { mutableStateOf<String?>(null) }
    var data by remember { mutableStateOf<JSONObject?>(null) }
    var refreshing by remember { mutableStateOf(false) }

    fun load() = scope.launch {
        refreshing = true
        val today = LocalDate.now()
        val (from, to) = when (range) {
            "today" -> today to today
            "yesterday" -> today.minusDays(1) to today.minusDays(1)
            else -> today.minusDays(range.toLong() - 1) to today
        }
        val query = listOfNotNull("from=$from", "to=$to", department?.let { "department=$it" }, status?.let { "status=$it" }).joinToString("&")
        repo.call { it.get("/riwayat?$query") }?.let { data = it }
        refreshing = false
    }
    LaunchedEffect(range, department, status) { load() }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("Riwayat checklist", fontWeight = FontWeight.SemiBold) },
                navigationIcon = { TextButton(onClick = onBack) { Text("‹ Kembali", color = Color.White) } },
                colors = TopAppBarDefaults.topAppBarColors(containerColor = Ink, titleContentColor = Color.White),
            )
        },
        containerColor = Cream,
    ) { padding ->
        Column(Modifier.padding(padding)) {
            Banner(repo)
            PullToRefreshBox(isRefreshing = refreshing, onRefresh = { load() }, modifier = Modifier.fillMaxSize()) {
                LazyColumn(contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp), modifier = Modifier.fillMaxSize()) {
                    item {
                        FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                            RANGES.forEach { (key, label) -> FilterChip(range == key, { range = key }, { Text(label) }) }
                        }
                    }
                    val departments = data?.optJSONArray("departments").objects()
                    if (departments.size > 1) item {
                        FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                            FilterChip(department == null, { department = null }, { Text("Semua departemen") })
                            departments.forEach { d -> FilterChip(department == d.getLong("id"), { department = d.getLong("id") }, { Text(d.optString("name")) }) }
                        }
                    }
                    item {
                        FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                            REVIEW.forEach { (key, label) -> FilterChip(status == key, { status = key }, { Text(label) }) }
                        }
                    }
                    val rows = data?.optJSONArray("checklists").objects()
                    when {
                        data == null -> item { Text(if (refreshing) "Memuat…" else "Belum bisa memuat. Tarik ke bawah untuk mencoba lagi.", color = Muted) }
                        rows.isEmpty() -> item { Text("Tidak ada checklist di rentang ini.", color = Muted) }
                    }
                    items(rows, key = { it.getLong("id") }) { c -> HistoryRow(c) { onOpen(c.getLong("id")) } }
                }
            }
        }
    }
}

@Composable
private fun HistoryRow(c: JSONObject, onClick: () -> Unit) {
    val p = c.optJSONObject("progress") ?: JSONObject()
    val (label, color) = when (c.optString("status")) {
        "reviewed" -> "Direview GM/HR" to Green
        "hod_reviewed" -> "Direview HOD" to Green
        else -> if (p.optBoolean("complete")) "Lengkap · belum direview" to Gold else "Belum lengkap ${p.optInt("filled")}/${p.optInt("total")}" to Amber
    }
    Column(Modifier.fillMaxWidth().background(Color.White, RoundedCornerShape(14.dp)).clickable(onClick = onClick).padding(14.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(c.s("employee") ?: c.optString("position"), fontWeight = FontWeight.SemiBold, color = Ink, modifier = Modifier.weight(1f))
            if (!p.isNull("score")) Text("${p.optInt("score")}%", fontWeight = FontWeight.Bold, color = when { p.optInt("score") >= 90 -> Green; p.optInt("score") >= 70 -> Gold; else -> Red })
        }
        Text(listOfNotNull(formatDate(c.optString("date")), c.optString("position"), c.s("department")).joinToString(" · "), color = Muted, fontSize = 13.sp)
        Pill(label, color)
    }
}
