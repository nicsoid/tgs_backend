// src/pages/Calendar.js - Simple Fixed Version

import React, { useState, useEffect } from "react";
import { useTranslation } from "react-i18next";
import { Link } from "react-router-dom";
import FullCalendar from "@fullcalendar/react";
import dayGridPlugin from "@fullcalendar/daygrid";
import timeGridPlugin from "@fullcalendar/timegrid";
import interactionPlugin from "@fullcalendar/interaction";
import axios from "axios";
import { useAuth } from "../contexts/AuthContext";
import { PencilIcon } from "@heroicons/react/outline";

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

      setEvents(response.data.events || []);
      setAvailableSlots(response.data.available_slots || []);
    } catch (error) {
      console.error("Failed to fetch calendar data:", error);
      setEvents([]);
      setAvailableSlots([]);
    }
  };

  const handleDateSet = (dateInfo) => {
    fetchCalendarData(dateInfo.start, dateInfo.end);
  };

  const handleEventClick = (clickInfo) => {
    // Don't show modal for available slots
    if (clickInfo.event.extendedProps?.isAvailableSlot) {
      return;
    }

    // Ensure we have valid extended props before setting
    if (
      clickInfo.event.extendedProps &&
      Object.keys(clickInfo.event.extendedProps).length > 0
    ) {
      setSelectedEvent(clickInfo.event.extendedProps);
    }
  };

  const handleGroupChange = (e) => {
    setSelectedGroup(e.target.value);
  };

  const renderEventContent = (eventInfo) => {
    return (
      <div className="text-xs p-1">
        <div className="font-semibold truncate">{eventInfo.timeText}</div>
        <div className="truncate">{eventInfo.event.title}</div>
      </div>
    );
  };

  const calendarEvents = [
    ...events.map((event) => ({
      ...event,
      // Show only the exact scheduled time, not +30 minutes
      end: event.start,
      // Ensure we don't override existing extendedProps
      extendedProps: {
        ...event.extendedProps,
        isAvailableSlot: false,
      },
    })),
    ...(showAvailableSlots
      ? availableSlots.map((slot, index) => ({
          id: `slot_${index}`,
          title: t("available"),
          start: slot.start,
          end: slot.end,
          backgroundColor: "#10B981",
          borderColor: "#10B981",
          classNames: ["available-slot"],
          extendedProps: {
            isAvailableSlot: true,
          },
        }))
      : []),
  ];

  // Get user's timezone, fallback to browser timezone if not set
  const getUserTimezone = () => {
    return (
      user?.settings?.timezone ||
      Intl.DateTimeFormat().resolvedOptions().timeZone
    );
  };

  // Determine if we're on mobile for responsive settings
  const isMobile = window.innerWidth < 768;

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-4">
        <h1 className="text-2xl font-semibold text-gray-900">
          {t("calendar")}
        </h1>

        {/* Mobile-responsive controls */}
        <div className="flex flex-col sm:flex-row sm:items-center gap-3 sm:gap-4">
          {/* Group selector - responsive width */}
          <select
            value={selectedGroup}
            onChange={handleGroupChange}
            className="w-full sm:w-auto min-w-0 sm:min-w-[200px] px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 text-sm"
          >
            <option value="">{t("all_groups")}</option>
            {groups.map((group) => (
              <option key={group._id || group.id} value={group._id || group.id}>
                {group.title}
              </option>
            ))}
          </select>

          {/* Available slots checkbox - better mobile layout */}
          <label className="flex items-center whitespace-nowrap">
            <input
              type="checkbox"
              checked={showAvailableSlots}
              onChange={(e) => setShowAvailableSlots(e.target.checked)}
              className="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded"
            />
            <span className="ml-2 text-sm text-gray-700">
              {t("show_available_slots")}
              {selectedGroup && (
                <span className="text-xs text-gray-500 block">
                  (for selected group)
                </span>
              )}
            </span>
          </label>
        </div>
      </div>

      <div className="bg-white shadow rounded-lg p-3 sm:p-6">
        <FullCalendar
          plugins={[dayGridPlugin, timeGridPlugin, interactionPlugin]}
          initialView={isMobile ? "timeGridDay" : "timeGridWeek"}
          headerToolbar={{
            left: isMobile ? "prev,next" : "prev,next today",
            center: "title",
            right: isMobile
              ? "timeGridDay"
              : "dayGridMonth,timeGridWeek,timeGridDay",
          }}
          events={calendarEvents}
          eventClick={handleEventClick}
          datesSet={handleDateSet}
          eventContent={renderEventContent}
          height="auto"
          // 24-hour display settings
          slotMinTime="00:00:00"
          slotMaxTime="24:00:00"
          // Time format and timezone settings
          timeZone={getUserTimezone()}
          locale={user?.settings?.language || "en"}
          // Time display format
          eventTimeFormat={{
            hour: "2-digit",
            minute: "2-digit",
            hour12: false,
          }}
          slotLabelFormat={{
            hour: "2-digit",
            minute: "2-digit",
            hour12: false,
          }}
          // Other display settings
          eventMaxStack={3}
          dayMaxEvents={true}
          allDaySlot={false}
          // Slot settings
          slotDuration="00:30:00"
          slotLabelInterval="01:00:00"
          // Week starts on Monday
          firstDay={1}
          // Better mobile responsiveness
          aspectRatio={isMobile ? 0.8 : 1.35}
          // Show events at their exact time without extending duration
          eventDisplay="block"
        />
      </div>

      {/* Enhanced Event Details Modal */}
      {selectedEvent && (
        <div className="fixed inset-0 bg-gray-500 bg-opacity-75 flex items-center justify-center z-50 p-4">
          <div className="bg-white rounded-lg max-w-lg w-full max-h-[90vh] overflow-y-auto">
            <div className="p-6">
              <div className="flex justify-between items-start mb-4">
                <h3 className="text-lg font-medium text-gray-900">
                  {t("post_details")}
                </h3>
                {selectedEvent.can_edit && (
                  <Link
                    to={`/posts/edit/${selectedEvent.post_id}`}
                    className="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                    onClick={() => setSelectedEvent(null)}
                  >
                    <PencilIcon className="w-4 h-4 mr-1" />
                    {t("edit_post")}
                  </Link>
                )}
              </div>

              <div className="space-y-4">
                {/* Groups - Enhanced for multiple groups */}
                <div>
                  <p className="text-sm font-medium text-gray-500">
                    {selectedEvent.groups_count > 1 ? t("groups") : t("group")}(
                    {selectedEvent.groups_count})
                  </p>
                  <div className="text-sm text-gray-900">
                    {selectedEvent.groups_count <= 3 ? (
                      // Show all groups if 3 or less
                      <div className="space-y-1">
                        {selectedEvent.groups.map((group, index) => (
                          <div key={index} className="flex items-center">
                            <span className="w-2 h-2 bg-blue-500 rounded-full mr-2"></span>
                            {group}
                          </div>
                        ))}
                      </div>
                    ) : (
                      // Show formatted text for many groups
                      <p>{selectedEvent.groups_text}</p>
                    )}
                  </div>
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
                    {t("status")}
                  </p>
                  <span
                    className={`inline-flex px-2 py-1 text-xs rounded-full ${
                      selectedEvent.status === "pending"
                        ? "bg-yellow-100 text-yellow-800"
                        : selectedEvent.status === "completed"
                        ? "bg-green-100 text-green-800"
                        : selectedEvent.status === "partially_sent"
                        ? "bg-blue-100 text-blue-800"
                        : "bg-red-100 text-red-800"
                    }`}
                  >
                    {t(`status_${selectedEvent.status}`)}
                  </span>
                </div>

                <div>
                  <p className="text-sm font-medium text-gray-500">
                    {t("message_preview")}
                  </p>
                  <div className="text-sm text-gray-900 bg-gray-50 p-3 rounded-md max-h-32 overflow-y-auto">
                    {selectedEvent.content_preview}
                    {selectedEvent.content_preview &&
                      selectedEvent.content_preview.length >= 150 &&
                      "..."}
                  </div>
                </div>
              </div>

              <div className="mt-6 flex justify-between">
                <div>
                  {!selectedEvent.can_edit && (
                    <p className="text-xs text-gray-500">
                      {t("cannot_edit_sent_post")}
                    </p>
                  )}
                </div>
                <button
                  onClick={() => setSelectedEvent(null)}
                  className="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50"
                >
                  {t("close")}
                </button>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

export default Calendar;
