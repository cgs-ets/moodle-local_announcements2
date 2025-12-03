import { useEffect, useState } from "react";
import { Box, Container, Center, Text, Loader, Breadcrumbs } from '@mantine/core';
import { useNavigate, useParams } from "react-router-dom";
import { Header } from "../../components/Header";
import { Footer } from "../../components/Footer";
import dayjs from "dayjs";
import { Link as RouterLink } from 'react-router-dom';
import { getConfig } from "../../utils";
import useFetch from "../../hooks/useFetch";


export function Edit() {
  let { id } = useParams();
  const api = useFetch();
  const [error, setError] = useState("")
  const [data, setData] = useState<any>(null)
  const navigate = useNavigate()

  useEffect(() => {
    document.title = 'Post Announcement'

    if (id) {
      getAnnouncement()
    }

    return () => {
    };
  }, [id]);


  const getAnnouncement = async () => {
    const fetchResponse = await api.call({
      query: {
        methodname: 'local_announcements2-get_activity',
        id: id,
      }
    })
    if (fetchResponse.error) {
      setError(fetchResponse.exception?.message ?? "Error")
      return
    }
    document.title = fetchResponse.data.activityname
    const data = {
      ...fetchResponse.data,
      audiences: JSON.parse(fetchResponse.data.audiencesjson || '[]'),
      timecreated: Number(fetchResponse.data.timecreated) ? fetchResponse.data.timecreated : dayjs().unix(),
      timemodified: Number(fetchResponse.data.timemodified) ? fetchResponse.data.timemodified : dayjs().unix(),
      timestart: Number(fetchResponse.data.timestart) ? fetchResponse.data.timestart : dayjs().unix(),
      timeend: Number(fetchResponse.data.timeend) ? fetchResponse.data.timeend : dayjs().unix(),
      immediate: !!Number(fetchResponse.data.immediate),
     
      // Move these into existing
      existing: fetchResponse.data.riskassessment,
      existingattachments: fetchResponse.data.attachments,
      attachments: "",
      riskassessment: "",
    }
    // Merge into default values
    setData({...data})
  }

  const handleSubmit = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setError("")

    let formData = JSON.parse(JSON.stringify({...data}))
    formData.categoriesjson = JSON.stringify(formData.categories)
    formData.studentlistjson = JSON.stringify(formData.studentlist)
    formData.planningstaffjson = JSON.stringify(formData.planningstaff)
    formData.accompanyingstaffjson = JSON.stringify(formData.accompanyingstaff)
    formData.staffinchargejson = JSON.stringify(formData.staffincharge.length ? formData.staffincharge[0] : '')
    formData.secondinchargejson = JSON.stringify(formData.secondincharge.length ? formData.secondincharge[0] : '')

    const response = await api.call({
      method: "POST",
      body: {
        methodname: 'local_announcements2-post_announcement',
        args: formData,
      }
    })

    if (response.error) {
      setError(response.exception?.message ?? "Error submitting form")
      return
    }

    // On success reload data to get any changes from server
    getAnnouncement()
  }

  // Only staff should be able to post/edit announcements
  if (!getConfig().roles?.includes("staff") || !id) {
    return ""
  }

  return (
    <>
      <Header />
      <div style={{minHeight: 'calc(100vh - 154px)'}}>

        {!error && !data.id 
          ? <Center h={200} mx="auto"><Loader type="dots" /></Center> : null
        }

        { error.length ?
          <Container size="xl">
            <Center h={300}>
              <Text fw={600} fz="lg">Failed to load activity...</Text>
              <Text c="red" mt="md">{error}</Text>
            </Center>
          </Container> : null
        }

        { (!error && data.id)?
          <>
            <Container size="xl">
              <Breadcrumbs fz="sm" mb="sm">
                <RouterLink to="/">
                  <Text c="blue">Announcements</Text>
                </RouterLink>
                <Text c="gray.6">Edit</Text>
              </Breadcrumbs>
            </Container>
            <Container size="xl" my="md">
              <form noValidate onSubmit={handleSubmit}>
                <Box className="flex flex-col gap-4 relative">
                  hellow
                </Box>
              </form>
          </Container>
        </> : null
        }
      </div>
      <Footer />
    </>
  )
}