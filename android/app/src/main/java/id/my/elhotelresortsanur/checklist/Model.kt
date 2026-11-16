package id.my.elhotelresortsanur.checklist

import org.json.JSONArray
import org.json.JSONObject

/*
 * Data checklist disimpan apa adanya sebagai JSON dari server (cache offline) dan dibaca ke kelas di bawah untuk layar.
 * Isian yang belum terkirim langsung ditulis ke JSON cache (applyItem / addLocalPhoto), lalu server menghitung ulang saat sinkron.
 */

data class Progress(val filled: Int, val total: Int, val done: Int, val pending: Int, val score: Int?, val complete: Boolean)

data class Summary(val id: Long, val date: String, val position: String, val status: String, val canEdit: Boolean, val progress: Progress)

data class Param(
    val id: Long, val name: String, val type: String, val unit: String?, val min: Double?, val max: Double?,
    val options: List<String>, val normal: String?, val value: String?,
)

/** Foto tersimpan di server (id) atau masih menunggu dikirim (local = path berkas di HP). */
data class Photo(val id: Long?, val local: String?)

data class Item(
    val id: Long, val section: String?, val title: String, val standard: String?, val status: String?, val note: String?,
    val params: List<Param>, val photos: List<Photo>,
)

data class Detail(
    val summary: Summary, val note: String?, val photoRequirement: String, val photoMax: Int, val pendingNoteRequired: Boolean,
    val items: List<Item>,
)

private fun JSONObject.str(key: String): String? = if (isNull(key)) null else optString(key)
private fun JSONObject.num(key: String): Double? = str(key)?.replace(',', '.')?.toDoubleOrNull()
private fun <T> JSONArray?.list(map: (JSONObject) -> T): List<T> =
    if (this == null) emptyList() else (0 until length()).map { map(getJSONObject(it)) }

fun parseProgress(o: JSONObject) = Progress(
    filled = o.optInt("filled"), total = o.optInt("total"), done = o.optInt("done"), pending = o.optInt("pending"),
    score = if (o.isNull("score")) null else o.optInt("score"), complete = o.optBoolean("complete"),
)

fun parseSummary(o: JSONObject) = Summary(
    id = o.getLong("id"), date = o.getString("date"), position = o.optString("position"), status = o.optString("status"),
    canEdit = o.optBoolean("can_edit"), progress = parseProgress(o.getJSONObject("progress")),
)

fun parseDetail(o: JSONObject) = Detail(
    summary = parseSummary(o),
    note = o.str("note"),
    photoRequirement = o.optString("photo_requirement", "optional"),
    photoMax = o.optInt("photo_max", 3),
    pendingNoteRequired = o.optBoolean("pending_note_required", true),
    items = o.optJSONArray("items").list { i ->
        Item(
            id = i.getLong("id"), section = i.str("section"), title = i.optString("title"), standard = i.str("standard"),
            status = i.str("status"), note = i.str("note"),
            params = i.optJSONArray("parameters").list { p ->
                Param(
                    id = p.getLong("id"), name = p.optString("name"), type = p.optString("type"), unit = p.str("unit"),
                    min = p.num("min"), max = p.num("max"),
                    options = p.optJSONArray("options")?.let { a -> (0 until a.length()).map { a.getString(it) } } ?: emptyList(),
                    normal = p.str("normal_option"), value = p.str("value"),
                )
            },
            photos = i.optJSONArray("photos").list { ph -> Photo(if (ph.has("id")) ph.getLong("id") else null, ph.str("local")) },
        )
    },
)

/** Status tugas dari parameternya, sama dengan `ParameterRules::status` di server (untuk tampilan sebelum sinkron). */
fun itemStatus(params: List<Param>): String? {
    val states = params.map { p ->
        val raw = p.value?.trim().orEmpty()
        when (p.type) {
            "status" -> when (raw) { "done" -> "ok"; "ongoing" -> "ongoing"; "pending" -> "pending"; else -> "empty" }
            "number", "meter" -> raw.replace(',', '.').toDoubleOrNull()?.let { n ->
                if ((p.min != null && n < p.min) || (p.max != null && n > p.max)) "pending" else "ok"
            } ?: "empty"
            "option" -> if (raw.isEmpty()) "empty" else if (p.normal == null || p.normal == raw) "ok" else "pending"
            "checkbox" -> if (raw == "1") "ok" else "empty"
            "text" -> if (raw.isEmpty()) "empty" else "ok"
            else -> "empty"
        }
    }
    return when {
        states.isEmpty() -> null
        "pending" in states -> "pending"
        "ongoing" in states -> "ongoing"
        states.all { it == "ok" } -> "done"
        else -> null
    }
}

/** Progres dari isian yang ada di HP (server menghitung ulang yang resmi). */
fun localProgress(d: Detail): Progress {
    val total = d.items.size
    val done = d.items.count { it.status == "done" }
    val missingReasons = if (d.pendingNoteRequired) d.items.count { it.status == "pending" && it.note.isNullOrBlank() } else 0
    val missingPhotos = when (d.photoRequirement) {
        "all" -> d.items.count { it.photos.isEmpty() }
        "pending" -> d.items.count { it.status == "pending" && it.photos.isEmpty() }
        else -> 0
    }
    val filled = d.items.count { it.status != null }
    return Progress(
        filled = filled, total = total, done = done, pending = d.items.count { it.status == "pending" },
        score = if (total == 0) null else Math.round(done * 100.0 / total).toInt(),
        complete = total > 0 && filled == total && missingReasons == 0 && missingPhotos == 0,
    )
}

private fun JSONObject.item(itemId: Long): JSONObject? =
    optJSONArray("items")?.let { a -> (0 until a.length()).map { a.getJSONObject(it) }.firstOrNull { it.getLong("id") == itemId } }

/**
 * Menulis isian satu tugas ke JSON cache, lalu menghitung ulang status tugas dan progres.
 * status hanya untuk tugas lama tanpa parameter (checklist sebelum fitur parameter); selain itu status dihitung dari parameter.
 */
fun applyItem(detail: JSONObject, itemId: Long, values: Map<Long, String?>, note: String?, status: String? = null) {
    val item = detail.item(itemId) ?: return
    val params = item.optJSONArray("parameters")
    if (params == null || params.length() == 0) item.put("status", status ?: JSONObject.NULL)
    if (params != null) for (i in 0 until params.length()) {
        val p = params.getJSONObject(i)
        if (values.containsKey(p.getLong("id"))) p.put("value", values[p.getLong("id")] ?: JSONObject.NULL)
    }
    item.put("note", note ?: JSONObject.NULL)
    refresh(detail)
}

fun addLocalPhoto(detail: JSONObject, itemId: Long, path: String) {
    detail.item(itemId)?.optJSONArray("photos")?.put(JSONObject().put("local", path))
    refresh(detail)
}

fun removePhoto(detail: JSONObject, itemId: Long, photo: Photo) {
    val photos = detail.item(itemId)?.optJSONArray("photos") ?: return
    for (i in 0 until photos.length()) {
        val p = photos.getJSONObject(i)
        if ((photo.id != null && p.optLong("id", -1) == photo.id) || (photo.local != null && p.optString("local") == photo.local)) {
            photos.remove(i); break
        }
    }
    refresh(detail)
}

private fun refresh(detail: JSONObject) {
    val parsed = parseDetail(detail)
    val items = detail.getJSONArray("items")
    parsed.items.forEachIndexed { i, item ->
        if (item.params.isNotEmpty()) items.getJSONObject(i).put("status", itemStatus(item.params) ?: JSONObject.NULL)
    }
    val p = localProgress(parseDetail(detail))
    detail.put("progress", JSONObject().put("filled", p.filled).put("total", p.total).put("done", p.done)
        .put("pending", p.pending).put("score", p.score ?: JSONObject.NULL).put("complete", p.complete))
}
