import { useState } from "react";
import { Box, Container, Center, Text, Loader, TextInput, Breadcrumbs, Modal, Button } from '@mantine/core';
import { useNavigate } from "react-router-dom";
import { Link as RouterLink } from 'react-router-dom';
import { Header } from "../../components/Header";
import { Footer } from "../../components/Footer";
import { getConfig } from "../../utils";
import useFetch from "../../hooks/useFetch";
import { FileUploader } from "../../components/FileUploader";
import { Announcement } from "../../types/types";
import { Editor } from "../../components/Editor";




export function Post() {
  const api = useFetch();
  const [error, setError] = useState("")
  const [data, setData] = useState<Announcement>({
    id: 0,
    username: "",
    subject: "",
    message: "",
    timecreated: "",
    timemodified: "",
    timestart: "",
    timeend: "",
    deleted: false,
    forcesend: false,
    attachments: "",
    existingattachments: [],
    uploadedimages: "",
  })
  
  const navigate = useNavigate()
  document.title = 'Post Announcement'

  const handleSubmit = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setError("")

    console.log(data)
    return

    let formData = JSON.parse(JSON.stringify({...data}))


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

    // On success, redirect to new ID if created.
    navigate('/' + response.data.id, {replace: true})
  }


  const updateField = (field: keyof Announcement, value: any) => {
    setData((state: Announcement) => ({
      ...state,
      [field]: value
    }))
  }

  const updateAttachments = (value: string) => {
    updateField('attachments', value)
  }

  // Only staff should be able to post/edit announcements
  if (!getConfig().roles?.includes("staff")) {
    return ""
  }

  return (
    <>
      <Header />
      <div style={{minHeight: 'calc(100vh - 154px)'}}>

        { error.length 
          ? <Container size="xl">
              <Center h={300}>
                <Text fw={600} fz="lg">Failed to post announcement...</Text>
                <Text c="red" mt="md">{error}</Text>
              </Center>
            </Container> 
          : <>
              <Container size="xl">
                <div className="page-header">
                  <Container size="xl" my="md" p={0} className="relative">
                      <Breadcrumbs fz="sm" mb="sm">
                        <RouterLink to="/">
                          <Text c="blue">Announcements</Text>
                        </RouterLink>
                        <Text c="gray.6">New</Text>
                      </Breadcrumbs>
                  </Container>
                </div>
              </Container>
              <Container size="xl" my="md">
                <form noValidate onSubmit={handleSubmit}>
                  <Box className="flex flex-col gap-4 relative">


                    <TextInput
                      label="Subject"
                      value={data.subject}
                      onChange={(e) => setData({...data, subject: e.target.value})}
                    />


                    <div>
                      <Text fz="sm" fw={500} c="#212529">Message</Text>
                      <Editor data={data} setData={setData} />
                    </div>

                    <div>
                      <Text fz="sm" fw={500} c="#212529">Attachments</Text>
                      <FileUploader 
                        desc={`Drag and drop files here...`} 
                        maxFiles={5} 
                        maxSize={10} 
                        existingfiles={[]} 
                        setState={updateAttachments} 
                      />
                    </div>


                    <Button 
                    type="submit"
                    variant="primary"
                    radius="xl"
                    size="md"
                    >Post Announcement</Button>




                  </Box>
                </form>
              </Container>
            </> 
        }

      </div>
      <Footer />

      
    </>
  )
}