// src/pages/ScheduledPosts.js

import React, { useState, useEffect } from "react";
import { Link } from "react-router-dom";
import axios from "axios";
import { format } from "date-fns";
import {
  CalendarIcon,
  ClockIcon,
  CurrencyDollarIcon,
  TrashIcon,
  PlusIcon,
  PencilIcon,
  UserGroupIcon,
} from "@heroicons/react/outline";
import { useTranslation } from "react-i18next";

const ScheduledPosts = () => {
  const { t } = useTranslation();
  const [posts, setPosts] = useState([]);
  const [loading, setLoading] = useState(true);
  const [page, setPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);

  useEffect(() => {
    fetchPosts();
  }, [page]);

  const fetchPosts = async () => {
    try {
      const response = await axios.get(
        `${process.env.REACT_APP_API_URL}/api/scheduled-posts?page=${page}`
      );
      setPosts(response.data.data);
      setTotalPages(response.data.last_page);
    } catch (error) {
      console.error("Failed to fetch posts:", error);
    } finally {
      setLoading(false);
    }
  };

  const deletePost = async (post) => {
    const postId = post.id || post._id;

    if (!postId) {
      console.error("No post ID found:", post);
      alert(t("no_post_id_error"));
      return;
    }

    if (!window.confirm(t("confirm_delete_post"))) {
      return;
    }

    try {
      await axios.delete(
        `${process.env.REACT_APP_API_URL}/api/scheduled-posts/${postId}`
      );

      setPosts(posts.filter((p) => (p.id || p._id) !== postId));
      alert(t("post_deleted_successfully"));
    } catch (error) {
      console.error("Failed to delete post:", error);
      alert(
        t("failed_to_delete_post") + " " + (error.response?.data?.error || "")
      );
    }
  };

  const getStatusBadge = (status) => {
    const statusClasses = {
      pending: "bg-yellow-100 text-yellow-800",
      partially_sent: "bg-blue-100 text-blue-800",
      completed: "bg-green-100 text-green-800",
      failed: "bg-red-100 text-red-800",
    };

    return (
      <span
        className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${statusClasses[status]}`}
      >
        {t(`status_${status}`)}
      </span>
    );
  };

  const renderGroupsList = (post) => {
    // Handle both old single group format and new multiple groups format
    const groups = post.groups_data || post.groups || [];
    const groupIds = post.group_ids || (post.group_id ? [post.group_id] : []);

    if (groups.length === 0 && post.group) {
      // Fallback to old format
      return (
        <span className="text-sm font-medium text-indigo-600">
          {post.group.title}
        </span>
      );
    }

    if (groups.length === 1) {
      return (
        <span className="text-sm font-medium text-indigo-600">
          {groups[0].title}
        </span>
      );
    }

    if (groups.length > 1) {
      return (
        <div className="flex items-center">
          <UserGroupIcon className="h-4 w-4 text-indigo-500 mr-1" />
          <span className="text-sm font-medium text-indigo-600">
            {groups.length} groups:{" "}
            {groups
              .slice(0, 2)
              .map((g) => g.title)
              .join(", ")}
            {groups.length > 2 && ` +${groups.length - 2} more`}
          </span>
        </div>
      );
    }

    return (
      <span className="text-sm text-gray-500">
        {groupIds.length} group{groupIds.length !== 1 ? "s" : ""}
      </span>
    );
  };

  if (loading) {
    return (
      <div className="flex justify-center items-center h-64">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-indigo-600"></div>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <div className="flex justify-between items-center">
        <h1 className="text-2xl font-semibold text-gray-900">
          {t("scheduled_posts")}
        </h1>
        <Link
          to="/posts/create"
          className="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
        >
          <PlusIcon className="-ml-1 mr-2 h-5 w-5" />
          {t("schedule_new_post")}
        </Link>
      </div>

      <div className="bg-white shadow overflow-hidden sm:rounded-md">
        {posts.length === 0 ? (
          <div className="text-center py-12">
            <CalendarIcon className="mx-auto h-12 w-12 text-gray-400" />
            <h3 className="mt-2 text-sm font-medium text-gray-900">
              {t("no_scheduled_posts")}
            </h3>
            <p className="mt-1 text-sm text-gray-500">
              {t("get_started_by_scheduling")}
            </p>
            <div className="mt-6">
              <Link
                to="/posts/create"
                className="inline-flex items-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
              >
                <PlusIcon className="-ml-1 mr-2 h-5 w-5" />
                {t("schedule_new_post")}
              </Link>
            </div>
          </div>
        ) : (
          <ul className="divide-y divide-gray-200">
            {posts.map((post) => (
              <li key={post._id}>
                <div className="px-4 py-4 sm:px-6">
                  <div className="flex items-center justify-between">
                    <div className="flex-1">
                      <div className="flex items-center justify-between">
                        {renderGroupsList(post)}
                        <div className="ml-2 flex-shrink-0 flex">
                          {getStatusBadge(post.status)}
                        </div>
                      </div>
                      <div className="mt-2 sm:flex sm:justify-between">
                        <div className="sm:flex">
                          <p className="flex items-center text-sm text-gray-500">
                            <ClockIcon className="flex-shrink-0 mr-1.5 h-5 w-5 text-gray-400" />
                            {post.schedule_times.length} {t("scheduled_times")}
                            {post.groups_count && post.groups_count > 1 && (
                              <span className="ml-2 text-xs bg-gray-100 px-2 py-1 rounded">
                                {post.total_scheduled ||
                                  post.schedule_times.length *
                                    post.groups_count}{" "}
                                total messages
                              </span>
                            )}
                          </p>
                          <p className="mt-2 flex items-center text-sm text-gray-500 sm:mt-0 sm:ml-6">
                            <CurrencyDollarIcon className="flex-shrink-0 mr-1.5 h-5 w-5 text-gray-400" />
                            {post.advertiser.amount_paid}{" "}
                            {post.advertiser.currency} {t("from")} @
                            {post.advertiser.telegram_username}
                          </p>
                        </div>
                        <div className="mt-2 flex items-center text-sm text-gray-500 sm:mt-0">
                          <p>
                            {t("created")}{" "}
                            {format(new Date(post.created_at), "MMM d, yyyy")}
                          </p>
                        </div>
                      </div>
                      <div className="mt-2">
                        <p className="text-sm text-gray-900 line-clamp-2">
                          {post.content.text}
                        </p>
                      </div>
                      {post.content.media && post.content.media.length > 0 && (
                        <div className="mt-2">
                          <p className="text-sm text-gray-500">
                            {post.content.media.length}{" "}
                            {t("media_files_attached")}
                          </p>
                        </div>
                      )}
                      <div className="mt-2">
                        <p className="text-sm text-gray-500">
                          {t("next_send")}:{" "}
                          {post.schedule_times
                            .filter((time) => new Date(time) > new Date())
                            .sort()[0]
                            ? format(
                                new Date(
                                  post.schedule_times
                                    .filter(
                                      (time) => new Date(time) > new Date()
                                    )
                                    .sort()[0]
                                ),
                                "MMM d, yyyy HH:mm"
                              )
                            : t("all_sent")}
                        </p>
                      </div>
                    </div>
                    {post.status === "pending" && (
                      <div className="ml-4 flex items-center space-x-2">
                        <Link
                          to={`/posts/edit/${post.id || post._id}`}
                          className="inline-flex items-center p-2 border border-transparent rounded-full shadow-sm text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
                        >
                          <PencilIcon className="h-5 w-5" />
                        </Link>
                        <button
                          onClick={() => deletePost(post)}
                          className="inline-flex items-center p-2 border border-transparent rounded-full shadow-sm text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500"
                        >
                          <TrashIcon className="h-5 w-5" />
                        </button>
                      </div>
                    )}
                  </div>
                </div>
              </li>
            ))}
          </ul>
        )}
      </div>

      {/* Pagination */}
      {totalPages > 1 && (
        <div className="bg-white px-4 py-3 flex items-center justify-between border-t border-gray-200 sm:px-6">
          <div className="flex-1 flex justify-between sm:hidden">
            <button
              onClick={() => setPage((p) => Math.max(1, p - 1))}
              disabled={page === 1}
              className="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 disabled:opacity-50"
            >
              {t("previous")}
            </button>
            <button
              onClick={() => setPage((p) => Math.min(totalPages, p + 1))}
              disabled={page === totalPages}
              className="ml-3 relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 disabled:opacity-50"
            >
              {t("next")}
            </button>
          </div>
          <div className="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
            <div>
              <p className="text-sm text-gray-700">
                {t("showing")}{" "}
                <span className="font-medium">{(page - 1) * 20 + 1}</span>{" "}
                {t("to")}{" "}
                <span className="font-medium">
                  {Math.min(page * 20, posts.length)}
                </span>{" "}
                {t("results")}
              </p>
            </div>
            <div>
              <nav
                className="relative z-0 inline-flex rounded-md shadow-sm -space-x-px"
                aria-label="Pagination"
              >
                <button
                  onClick={() => setPage((p) => Math.max(1, p - 1))}
                  disabled={page === 1}
                  className="relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50 disabled:opacity-50"
                >
                  {t("previous")}
                </button>
                <span className="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700">
                  {page} / {totalPages}
                </span>
                <button
                  onClick={() => setPage((p) => Math.min(totalPages, p + 1))}
                  disabled={page === totalPages}
                  className="relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50 disabled:opacity-50"
                >
                  {t("next")}
                </button>
              </nav>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

export default ScheduledPosts;
