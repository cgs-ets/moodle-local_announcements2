import { useParams } from "react-router-dom";
import useFetch from "../../hooks/useFetch";
import { useEffect, useState } from "react";

export function Preview() {
  let { id } = useParams();
  const api = useFetch()
  const [activity, setActivity] = useState(null)

  useEffect(() => {
    document.title = 'Activity Preview';

    if (id) {
      getActivity()
    }
  }, [id]);

  const getActivity = async () => {

    const fetchResponse = await api.call({
      query: {
        methodname: 'local_announcements2-get_activity_with_permission',
        id: id,
      }
    })
    if (fetchResponse.error) {
      return
    }
    setActivity(fetchResponse.data)
  }


  return (
    <div>
      <h1>Preview</h1>
    </div>
  );
};