package id.my.elhotelresortsanur.checklist

import org.json.JSONObject
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/** Status lokal harus sama dengan ParameterRules::status di server, supaya tampilan offline tidak menyesatkan. */
class ModelTest {
    private fun p(type: String, value: String?, min: Double? = null, max: Double? = null, normal: String? = null) =
        Param(1, "x", type, null, min, max, listOf("Baik", "Rusak"), normal, value)

    @Test fun statusFollowsServerRules() {
        assertNull(itemStatus(emptyList()))
        assertEquals("done", itemStatus(listOf(p("status", "done"))))
        assertEquals("pending", itemStatus(listOf(p("status", "done"), p("number", "250", max = 240.0))))
        assertEquals("done", itemStatus(listOf(p("meter", "220,5", min = 200.0, max = 240.0), p("checkbox", "1"))))
        assertEquals("pending", itemStatus(listOf(p("option", "Rusak", normal = "Baik"))))
        assertEquals("ongoing", itemStatus(listOf(p("status", "ongoing"), p("text", "ok"))))
        assertNull(itemStatus(listOf(p("status", "done"), p("text", " "))))
    }

    @Test fun applyItemUpdatesCacheAndProgress() {
        val json = JSONObject(
            """{"id":5,"date":"2026-11-16","position":"GSA","status":"saved","can_edit":true,
            "progress":{"filled":0,"total":2},"photo_requirement":"pending","pending_note_required":true,
            "items":[{"id":1,"title":"A","parameters":[{"id":10,"type":"status","value":null}],"photos":[]},
                     {"id":2,"title":"B","parameters":[{"id":20,"type":"status","value":null}],"photos":[]}]}"""
        )
        applyItem(json, 1, mapOf(10L to "done"), null)
        applyItem(json, 2, mapOf(20L to "pending"), null)
        var d = parseDetail(json)
        assertEquals("pending", d.items[1].status)
        assertEquals(2, d.summary.progress.filled)
        assertEquals(50, d.summary.progress.score)
        assertFalse(d.summary.progress.complete) // Pending tanpa alasan dan tanpa foto

        applyItem(json, 2, mapOf(20L to "pending"), "Lampu mati")
        addLocalPhoto(json, 2, "/x.jpg")
        d = parseDetail(json)
        assertTrue(d.summary.progress.complete)
        removePhoto(json, 2, Photo(null, "/x.jpg"))
        assertFalse(parseDetail(json).summary.progress.complete)
    }
}
