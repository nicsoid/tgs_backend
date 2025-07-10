// src/pages/Calendar.js

import React, { useState, useEffect } from "react";
import { useTranslation } from "react-i18next";
import FullCalendar from "@fullcalendar/react";
import dayGridPlugin from "@fullcalendar/daygrid";
import timeGridPlugin from "@fullcalendar/timegrid";
import interactionPlugin from "@fullcalendar/interaction";
import axios from "axios";
import { useAuth } from "../contexts/AuthContext";

const Calendar = () => {
  const { t } = useTranslation();
  const { user } = useAuth();
  const [events, setEvents] = useState([]);
  const [availableSlots, setAvailableSlots] = useState([]);
  const [selectedGroup, setSelectedGroup] = useState("");
  const [groups, setGroups] = useState([]);
  const [showAvailableSlots, setShowAvailableSlots] = useState(false);
  const [selectedEvent, setSelectedEvent] = useState(null);

  useEffect(() => {
    fetchGroups();
  }, []);

  const fetchGroups = async () => {
    try {
      const response = await axios.get(
        `${process.env.REACT_APP_API_URL}/api/groups`
      );
      setGroups(response.data);
    } catch (error) {
      console.error("Failed to fetch groups:", error);
    }
  };

  const fetchCalendarData = async (start, end) => {
    try {
      const params = {
        start_date: start.toISOString().split("T")[0],
        end_date: end.toISOString().split("T")[0],
      };

      if (selectedGroup) {
        params.group_id = selectedGroup;
      }

      const response = await axios.get(
        `${process.env.REACT_APP_API_URL}/api/calendar`,
        { params }
      );

      setEvents(response.data.events);
      setAvailableSlots(response.data.available_slots);
    } catch (error) {
      console.error("Failed to fetch calendar data:", error);
    }
  };

  const handleDateSet = (dateInfo) => {
    fetchCalendarData(dateInfo.start, dateInfo.end);
  };

  const handleEventClick = (clickInfo) => {
    setSelectedEvent(clickInfo.event.extendedProps);
  };

  const renderEventContent = (eventInfo) => {
    return (
      <>
        <div className="text-xs font-semibold">{eventInfo.timeText}</div>
        <div className="text-xs">{eventInfo.event.title}</div>
      </>
    );
  };

  const calendarEvents = [
    ...events,
    ...(showAvailableSlots
      ? availableSlots.map((slot, index) => ({
          id: `slot_${index}`,
          title: t("available"),
          start: slot.start,
          end: slot.end,
          backgroundColor: "#10B981",
          borderColor: "#10B981",
          classNames: ["available-slot"],
        }))
      : []),
  ];

  return (
    <div className="space-y-6">
      <div className="flex justify-between items-center">
        <h1 className="text-2xl font-semibold text-gray-900">
          {t("calendar")}
        </h1>

        <div className="flex items-center space-x-4">
          <select
            value={selectedGroup}
            onChange={(e) => setSelectedGroup(e.target.value)}
            className="px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
          >
            <option value="">{t("all_groups")}</option>
            {groups.map((group) => (
              <option key={group._id} value={group._id}>
                {group.title}
              </option>
            ))}
          </select>

          <label className="flex items-center">
            <input
              type="checkbox"
              checked={showAvailableSlots}
              onChange={(e) => setShowAvailableSlots(e.target.checked)}
              className="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded"
            />
            <span className="ml-2 text-sm text-gray-700">
              {t("show_available_slots")}
            </span>
          </label>
        </div>
      </div>

      <div className="bg-white shadow rounded-lg p-6">
        <FullCalendar
          plugins={[dayGridPlugin, timeGridPlugin, interactionPlugin]}
          initialView="timeGridWeek"
          headerToolbar={{
            left: "prev,next today",
            center: "title",
            right: "dayGridMonth,timeGridWeek,timeGridDay",
          }}
          events={calendarEvents}
          eventClick={handleEventClick}
          datesSet={handleDateSet}
          eventContent={renderEventContent}
          height="auto"
          slotMinTime="06:00:00"
          slotMaxTime="24:00:00"
          locale={user?.settings?.language || "en"}
          timeZone={user?.settings?.timezone || "UTC"}
        />
      </div>

      {/* Event Details Modal */}
      {selectedEvent && (
        <div className="fixed inset-0 bg-gray-500 bg-opacity-75 flex items-center justify-center z-50">
          <div className="bg-white rounded-lg max-w-md w-full p-6">
            <h3 className="text-lg font-medium text-gray-900 mb-4">
              {t("post_details")}
            </h3>

            <div className="space-y-3">
              <div>
                <p className="text-sm font-medium text-gray-500">
                  {t("group")}
                </p>
                <p className="text-sm text-gray-900">{selectedEvent.group}</p>
              </div>

              <div>
                <p className="text-sm font-medium text-gray-500">
                  {t("advertiser")}
                </p>
                <p className="text-sm text-gray-900">
                  @{selectedEvent.advertiser}
                </p>
              </div>

              <div>
                <p className="text-sm font-medium text-gray-500">
                  {t("amount")}
                </p>
                <p className="text-sm text-gray-900">
                  {selectedEvent.currency} {selectedEvent.amount}
                </p>
              </div>

              <div>
                <p className="text-sm font-medium text-gray-500">
                  {t("preview")}
                </p>
                <p className="text-sm text-gray-900">
                  {selectedEvent.content_preview}...
                </p>
              </div>

              <div>
                <p className="text-sm font-medium text-gray-500">
                  {t("status")}
                </p>
                <span
                  className={`inline-flex px-2 py-1 text-xs rounded-full ${
                    selectedEvent.status === "pending"
                      ? "bg-yellow-100 text-yellow-800"
                      : selectedEvent.status === "completed"
                      ? "bg-green-100 text-green-800"
                      : "bg-blue-100 text-blue-800"
                  }`}
                >
                  {t(`status_${selectedEvent.status}`)}
                </span>
              </div>
            </div>

            <div className="mt-6 flex justify-end">
              <button
                onClick={() => setSelectedEvent(null)}
                className="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50"
              >
                {t("close")}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

export default Calendar;
